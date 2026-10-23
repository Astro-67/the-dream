<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Billing\FolioSummary;
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
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\AssignableRooms;
use App\Domains\Reservations\BookingRules;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CreateReservationRequest;
use App\Support\Money\Currency;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function create(CreateReservationRequest $request, StayRestrictions $restrictions): Response
    {
        $stay = $request->validated();
        $type = RoomType::query()->find($stay['room_type_id'] ?? null);
        $plans = RatePlan::query()->whereNull('deactivated_at')->with('roomTypes:id,name')->orderBy('name')->orderBy('id')->get();
        $plan = isset($stay['rate_plan_id']) ? $plans->firstWhere('id', $stay['rate_plan_id']) : $plans->first(fn (RatePlan $plan) => $type === null || $plan->roomTypes->contains('id', $type->id));
        $stay['rate_plan_id'] = $plan?->id;

        return Inertia::render('reservations/create', [
            'stay' => $stay,
            'rooms' => app(AssignableRooms::class)->forStay(null, $stay['arrival'], $stay['departure']),
            'roomType' => $type?->only(['id', 'name']),
            'ratePlan' => $plan?->only(['id', 'name', 'currency']),
            'ratePlans' => $plans->map(fn (RatePlan $plan) => [...$plan->only(['id', 'name', 'currency']), 'room_type_ids' => $plan->roomTypes->modelKeys()]),
            'restrictionWarnings' => [...app(BookingRules::class)->warnings(Tenancy::property(), $stay['arrival'], $stay['departure']), ...($plan && $type ? $restrictions->warnings($plan, $type, $stay['arrival'], $stay['departure']) : [])],
            'nightlyRate' => $plan && $type ? (app(StayPricing::class)->quote($plan, $type, $stay['arrival'], $stay['departure'], (int) $stay['adults'], (int) $stay['children'])->nights[0]['total'] ?? null) : null,
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
        $property = Property::query()->findOrFail($reservation->property_id);
        $canMove = $reservation->status === ReservationStatus::CheckedIn && Gate::allows('moveRoom', $reservation);
        $folios = Folio::query()->where('reservation_id', $reservation->id)->withSum('postings as balance_amount', 'amount')
            ->orderBy('created_at')->orderBy('id')->paginate(50, ['*'], 'folios_page')->withQueryString();
        $folioPage = clone $folios;
        $folioPage->setCollection(clone $folios->getCollection());
        $paymentRange = Posting::query()->where('property_id', $property->id)
            ->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id))
            ->moneyMovements()
            ->selectRaw('MIN(business_date) as first_date, MAX(business_date) as last_date')->toBase()->first();

        return Inertia::render('reservations/show', [
            'paymentsReportUrl' => Gate::allows('view', PropertyKpis::class) ? route('payments.index', [
                'reservation' => $reservation->id, 'period' => 'custom',
                'from' => CarbonImmutable::parse($paymentRange->first_date ?? BusinessDate::current())->toDateString(),
                'to' => CarbonImmutable::parse($paymentRange->last_date ?? BusinessDate::current())->toDateString(),
            ]) : null,
            'folios' => $folioPage->through(fn (Folio $folio, int $index) => [
                'id' => $folio->id, 'currency' => $folio->currency->value, 'balance' => (int) $folio->getAttribute('balance_amount'),
                'number' => ($folios->firstItem() ?? 1) + $index,
                'payments' => $folio->postings()->moneyMovements()
                    ->with(['actor:id,name', 'receipt:id,posting_id,number'])->reorder()->orderByDesc('posted_at')->orderByDesc('id')
                    ->paginate(50, ['*'], 'payments_page_'.$folio->id)->withQueryString()->through(fn (Posting $posting) => [
                        ...$posting->only(['id', 'type', 'category', 'amount', 'method', 'reference']),
                        'posted_at' => $posting->posted_at->toIso8601String(), 'actor' => $posting->actor?->name,
                        'receipt' => $posting->receipt?->only(['id', 'number']),
                    ]),
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
            'totals' => FolioSummary::totals($reservation),
            'stay' => $reservation->stay(),
            'checkoutBalances' => FolioSummary::checkoutBalances(collect([$reservation]))[$reservation->id] ?? [],
            'feeProposals' => FeeProposal::query()->where('reservation_id', $reservation->id)->orderBy('created_at')->get()->map(fn (FeeProposal $fee) => [
                ...$fee->only(['id', 'kind', 'amount', 'status', 'reason', 'fiscal_exception_candidate']), 'currency' => $fee->currency->value,
                'can_resolve' => $fee->status === 'proposed' && Gate::allows('resolve', $fee),
            ])->all(),
            'canPostCharge' => $folios->isNotEmpty() && Gate::allows('postCharge', $folios->first()),
            'canReverse' => $folios->isNotEmpty() && Gate::allows('reverse', $folios->first()),
            'canTransfer' => $folios->total() > 1 && $folios->isNotEmpty() && Gate::allows('transfer', $folios->first()),
            'canOpenFolio' => Gate::allows('create', [Folio::class, $reservation]),
            'folioCurrencies' => array_map(fn (Currency $currency) => $currency->value, JurisdictionRules::allowedCurrencies($property)),
            'canRecordPayment' => $folios->isNotEmpty() && Gate::allows('recordPayment', $folios->first()),
            'acceptedPaymentMethods' => $property->settings()['payment_settings']['accepted_methods'],
            'blockCheckoutIfUnpaid' => $property->settings()['payment_settings']['block_checkout_if_unpaid'],
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
            'can' => ['checkIn' => $reservation->status === ReservationStatus::Confirmed && $reservation->arrival->toDateString() <= BusinessDate::current() && $reservation->departure->toDateString() > BusinessDate::current() && Gate::allows('checkIn', $reservation),
                'cancel' => Gate::allows('cancel', $reservation), 'void' => Gate::allows('void', $reservation), 'moveRoom' => $canMove, 'changeStay' => Gate::allows('changeStay', $reservation),
                'checkOut' => Gate::allows('checkOut', $reservation), 'overrideBalance' => Gate::allows('checkOutWithBalance', $reservation)],
            'moveRooms' => $canMove ? app(AssignableRooms::class)->forMove($reservation) : [],
            'leadGuest' => $reservation->leadGuest()?->only(['id', 'name', 'email', 'phone', 'nationality']),
            'booker' => Booker::query()->find($reservation->getAttribute('booker_id'))?->only(['id', 'name']),
            'roomType' => RoomType::query()->findOrFail($reservation->getAttribute('room_type_id'))->only(['id', 'name']),
            'assignedRoom' => Room::query()->find($reservation->room_id)?->number,
            'ratePlan' => RatePlan::query()->findOrFail($reservation->getAttribute('rate_plan_id'))->only(['id', 'name']),
        ]);
    }

    /** The guest-facing settlement statement: posted figures per folio, never identity documents or staff notes. */
    public function statement(Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);
        $folios = FolioSummary::folios($reservation);

        return Inertia::render('reservations/statement', [
            'reservation' => [...$reservation->stay(), ...$reservation->only(['adults', 'children'])],
            'folios' => $folios->map(fn (array $folio, int $index) => [
                'number' => $index + 1, 'currency' => $folio['folio']->currency->value, 'summary' => $folio['summary'],
                'postings' => $folio['postings']->map(fn (Posting $posting) => [
                    ...$posting->only(['id', 'type', 'category', 'description', 'quantity', 'unit_price', 'amount', 'method']),
                    'business_date' => $posting->business_date->toDateString(),
                    'tax_lines' => array_map(fn (array $line) => ['name' => $line['name'], 'inclusive' => $line['inclusive'], 'amount' => $line['amount'], 'unavailable' => $line['unavailable'] ?? false], $posting->tax_lines),
                ])->values(),
            ])->values(),
            'totals' => FolioSummary::totals($reservation, $folios),
            'property' => Property::query()->findOrFail($reservation->property_id)->only(['name', 'address_line', 'city', 'phone', 'email']),
        ]);
    }
}
