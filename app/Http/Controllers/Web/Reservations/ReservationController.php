<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
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
            'bookers' => Booker::query()->orderBy('name')->paginate(50)->withQueryString(),
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
        // ponytail: a stay's postings are bounded by its nights and extras; paginate if long stays make this heavy.
        $folios = Folio::query()->where('reservation_id', $reservation->id)->with(['postings.actor:id,name', 'postings.receipt:id,posting_id,number'])->orderBy('created_at')->get();
        // Reversals and transfers link to the posting they cancel; show the link from both ends.
        $postings = $folios->flatMap(fn (Folio $folio) => $folio->postings)->keyBy('id');
        $cancelledBy = $postings->whereNotNull('related_posting_id')->keyBy('related_posting_id');

        return Inertia::render('reservations/show', [
            'folios' => $folios->map(fn (Folio $folio) => [
                'id' => $folio->id, 'currency' => $folio->currency->value, 'balance' => $folio->balance()->amount,
                'postings' => $folio->postings->map(fn (Posting $posting) => [
                    ...$posting->only(['id', 'type', 'category', 'description', 'reason', 'quantity', 'unit_price', 'net', 'amount', 'tax_lines']),
                    'posted_at' => $posting->posted_at->toIso8601String(), 'actor' => $posting->actor?->name,
                    'related' => ($related = $postings->get($posting->related_posting_id ?? '')) === null ? null : $related->only(['id', 'type', 'category', 'folio_id']),
                    'cancelled_by' => $cancelledBy->get($posting->id)?->only(['id', 'type', 'folio_id']),
                    ...$posting->only(['method', 'reference', 'card_last_four', 'card_brand']), 'receipt' => $posting->receipt?->only(['id', 'number']),
                ])->all(),
            ])->all(),
            'totals' => $folios->groupBy(fn (Folio $folio) => $folio->currency->value)->map(fn ($group, string $currency) => [
                'currency' => $currency,
                'charges' => (int) $group->sum(fn (Folio $folio) => $folio->postings->where('type', 'charge')->sum('amount')),
                'balance' => (int) $group->sum(fn (Folio $folio) => $folio->balance()->amount),
            ])->values()->all(),
            'feeProposals' => FeeProposal::query()->where('reservation_id', $reservation->id)->orderBy('created_at')->get()->map(fn (FeeProposal $fee) => [
                ...$fee->only(['id', 'kind', 'amount', 'status', 'reason', 'fiscal_exception_candidate']), 'currency' => $fee->currency->value,
                'can_resolve' => $fee->status === 'proposed' && Gate::allows('resolve', $fee),
            ])->all(),
            'canPostCharge' => $folios->isNotEmpty() && Gate::allows('postCharge', $folios->first()),
            'canReverse' => $folios->isNotEmpty() && Gate::allows('reverse', $folios->first()),
            'canTransfer' => $folios->count() > 1 && Gate::allows('transfer', $folios->first()),
            'canOpenFolio' => Gate::allows('create', [Folio::class, $reservation]),
            'folioCurrencies' => array_map(fn (Currency $currency) => $currency->value, JurisdictionRules::allowedCurrencies(Property::query()->findOrFail($reservation->property_id))),
            'canRecordPayment' => $folios->isNotEmpty() && Gate::allows('recordPayment', $folios->first()),
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
            'can' => ['cancel' => Gate::allows('cancel', $reservation), 'void' => Gate::allows('void', $reservation), 'moveRoom' => $canMove, 'changeStay' => Gate::allows('changeStay', $reservation)],
            'moveRooms' => $canMove ? app(AssignableRooms::class)->forMove($reservation) : [],
            'booker' => Booker::query()->findOrFail($reservation->getAttribute('booker_id'))->only(['id', 'name']),
            'roomType' => RoomType::query()->findOrFail($reservation->getAttribute('room_type_id'))->only(['id', 'name']),
            'ratePlan' => RatePlan::query()->findOrFail($reservation->getAttribute('rate_plan_id'))->only(['id', 'name']),
        ]);
    }
}
