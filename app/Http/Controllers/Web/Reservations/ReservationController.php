<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayPricing;
use App\Domains\Rates\StayRestrictions;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\AssignableRooms;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CreateReservationRequest;
use App\Support\Money\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function create(CreateReservationRequest $request, StayRestrictions $restrictions): Response
    {
        $type = RoomType::query()->findOrFail($request->validated('room_type_id'));
        $plan = RatePlan::query()->findOrFail($request->validated('rate_plan_id'));

        return Inertia::render('reservations/create', [
            'stay' => $request->validated(),
            'rooms' => app(AssignableRooms::class)->forStay($request->validated('room_type_id'), $request->validated('arrival'), $request->validated('departure')),
            'roomType' => $type->only(['id', 'name']),
            'ratePlan' => $plan->only(['id', 'name']),
            'restrictionWarnings' => $restrictions->warnings($plan, $type, $request->validated('arrival'), $request->validated('departure')),
            'nightlyRate' => app(StayPricing::class)->quote($plan, $type, $request->validated('arrival'), $request->validated('departure'), (int) $request->validated('adults'), (int) $request->validated('children'))->nights[0]['total'] ?? null,
            'businessDate' => BusinessDate::current(),
        ]);
    }

    public function store(CreateReservationRequest $request, CreateReservation $create): RedirectResponse
    {
        $reservation = $create->handle($request->user(), $request->validated());

        return to_route('reservations.show', $reservation)->with('toast', ['type' => 'success', 'message' => __('Reservation created.')]);
    }

    public function show(Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);
        $canMove = $reservation->status === ReservationStatus::CheckedIn && Gate::allows('moveRoom', $reservation);
        $folios = Folio::query()->where('reservation_id', $reservation->id)->withSum('postings as balance_amount', 'amount')
            ->orderBy('created_at')->orderBy('id')->paginate(50, ['*'], 'folios_page')->withQueryString();
        $totals = Folio::query()->where('folios.reservation_id', $reservation->id)
            ->leftJoin('postings', 'postings.folio_id', '=', 'folios.id')->select('folios.currency')
            ->selectRaw("COALESCE(SUM(postings.amount), 0) as balance_total, COALESCE(SUM(CASE WHEN postings.type = 'charge' THEN postings.amount ELSE 0 END), 0) as charge_total")
            ->groupBy('folios.currency')->orderBy('folios.currency')->get();
        $folioPage = clone $folios;
        $folioPage->setCollection(clone $folios->getCollection());

        return Inertia::render('reservations/show', [
            'folios' => $folioPage->through(fn (Folio $folio, int $index) => [
                'id' => $folio->id, 'currency' => $folio->currency->value, 'balance' => (int) $folio->getAttribute('balance_amount'),
                'number' => ($folios->firstItem() ?? 1) + $index,
                'postings' => $folio->postings()->with(['actor:id,name', 'receipt:id,posting_id,number', 'related:id,type,category,folio_id', 'cancelledBy:id,type,folio_id,related_posting_id', 'cancelledBy.cancelledBy:id,folio_id,related_posting_id'])
                    ->paginate(50, ['*'], 'postings_page_'.$folio->id)->withQueryString()->through(fn (Posting $posting) => [
                        ...$posting->only(['id', 'type', 'category', 'description', 'reason', 'quantity', 'unit_price', 'net', 'amount', 'tax_lines']),
                        'posted_at' => $posting->posted_at->toIso8601String(), 'actor' => $posting->actor?->name,
                        'related' => $posting->related?->only(['id', 'type', 'category', 'folio_id']),
                        'cancelled_by' => $posting->cancelledBy === null ? null : [
                            ...$posting->cancelledBy->only(['id', 'type', 'folio_id']),
                            'destination_folio_id' => match ($posting->cancelledBy->type) {
                                'transfer_out' => $posting->cancelledBy->cancelledBy?->folio_id,
                                'transfer_in' => $posting->cancelledBy->folio_id,
                                default => null,
                            },
                        ],
                        ...$posting->only(['method', 'reference', 'card_last_four', 'card_brand']), 'receipt' => $posting->receipt?->only(['id', 'number']),
                    ]),
            ]),
            'totals' => $totals->map(fn (Folio $total) => [
                'currency' => $total->currency->value,
                'charges' => (int) $total->getAttribute('charge_total'),
                'balance' => (int) $total->getAttribute('balance_total'),
            ])->values()->all(),
            'feeProposals' => FeeProposal::query()->where('reservation_id', $reservation->id)->orderBy('created_at')->get()->map(fn (FeeProposal $fee) => [
                ...$fee->only(['id', 'kind', 'amount', 'status', 'reason', 'fiscal_exception_candidate']), 'currency' => $fee->currency->value,
                'can_resolve' => $fee->status === 'proposed' && Gate::allows('resolve', $fee),
            ])->all(),
            'canPostCharge' => $folios->isNotEmpty() && Gate::allows('postCharge', $folios->first()),
            'canReverse' => $folios->isNotEmpty() && Gate::allows('reverse', $folios->first()),
            'canTransfer' => $folios->total() > 1 && $folios->isNotEmpty() && Gate::allows('transfer', $folios->first()),
            'canOpenFolio' => Gate::allows('create', [Folio::class, $reservation]),
            'folioCurrencies' => array_map(fn (Currency $currency) => $currency->value, JurisdictionRules::allowedCurrencies(Property::query()->findOrFail($reservation->property_id))),
            'canRecordPayment' => $folios->isNotEmpty() && Gate::allows('recordPayment', $folios->first()),
            'canRefund' => $folios->isNotEmpty() && Gate::allows('refund', $folios->first()),
            'canWriteOff' => $folios->isNotEmpty() && Gate::allows('writeOff', $folios->first()),
            'reservation' => $reservation->load('priceLines'),
            'groupMembers' => $reservation->getAttribute('booking_group_id') === null ? [] : Reservation::query()
                ->where('property_id', $reservation->property_id)
                ->where('booking_group_id', $reservation->getAttribute('booking_group_id'))
                ->whereKeyNot($reservation->id)->orderBy('reservation_number')
                ->get(['reservation_number', 'status', 'arrival', 'departure']),
            'rooms' => app(AssignableRooms::class)->forStay($reservation->getAttribute('room_type_id'), $reservation->arrival->toDateString(), $reservation->departure->toDateString(), $reservation->id),
            'amendments' => $reservation->lifecycleEvents()->where('type', 'modified')->whereNotNull('snapshot->before')->with('actor:id,name')->paginate(50, ['*'], 'amendments_page')->withQueryString(),
            'amendmentOptions' => Gate::allows('amend', $reservation) ? [
                'roomTypes' => RoomType::query()->orderBy('name')->get(['id', 'name']),
                'ratePlans' => RatePlan::query()->whereNull('deactivated_at')->with('roomTypes:id')->orderBy('name')->get(['id', 'name']),
                'rooms' => Room::query()->whereNull('deactivated_at')->orderBy('number')->get(['id', 'number', 'room_type_id', 'property_id'])->each(fn (Room $room) => app(AssignableRooms::class)->shapeOption($room)),
            ] : null,
            'canAmend' => Gate::allows('amend', $reservation),
            'canAssignRoom' => Gate::allows('assignRoom', $reservation),
            'can' => ['cancel' => Gate::allows('cancel', $reservation), 'void' => Gate::allows('void', $reservation), 'moveRoom' => $canMove, 'changeStay' => Gate::allows('changeStay', $reservation),
                'checkOut' => Gate::allows('checkOut', $reservation), 'overrideBalance' => Gate::allows('checkOutWithBalance', $reservation)],
            'moveRooms' => $canMove ? app(AssignableRooms::class)->forMove($reservation) : [],
            'leadGuest' => $reservation->leadGuest()?->only(['id', 'name', 'email', 'phone', 'nationality']),
            'booker' => Booker::query()->find($reservation->getAttribute('booker_id'))?->only(['id', 'name']),
            'roomType' => RoomType::query()->findOrFail($reservation->getAttribute('room_type_id'))->only(['id', 'name']),
            'ratePlan' => RatePlan::query()->findOrFail($reservation->getAttribute('rate_plan_id'))->only(['id', 'name']),
        ]);
    }
}
