<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\FrontDesk\Models\ExceptionListItem;
use App\Domains\Housekeeping\Actions\MarkStayoverService;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Reservations\Actions\ProposeNoShows;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseBusinessDate
{
    public function handle(?User $actor, Property $property, string $date): BusinessDateClose
    {
        return Audit::validationRefusal($actor, $property, 'Night audit refused', fn () => DB::transaction(function () use ($actor, $property, $date) {
            if (Tenancy::property()?->id !== $property->id) {
                throw ValidationException::withMessages(['property' => __('Night audit requires the property in context.')]);
            }
            $property = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            $existing = BusinessDateClose::query()->where('property_id', $property->id)->whereDate('business_date', $date)->first();
            if ($existing?->completed_at !== null) {
                return $existing;
            }
            if (BusinessDate::of($property) !== $date) {
                throw ValidationException::withMessages(['business_date' => __('Only the current business date can be closed.')]);
            }
            $close = $existing ?? BusinessDateClose::query()->create(['business_date' => $date, 'actor_id' => $actor?->id]);
            $reservations = Reservation::query()->where('property_id', $property->id)
                ->where('status', ReservationStatus::CheckedIn)->whereDate('arrival', '<=', $date)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $charge = app(PostRoomCharge::class)->handle($actor, $reservation, $date);
                $this->recordPriceChange($actor, $reservation, $charge, $date);
            }
            app(ProposeNoShows::class)->handle($property, $date);
            $nextDate = $property->business_date->addDay()->toDateString();
            $this->carryExceptions($close, $property, $nextDate);
            $this->markStayovers($actor, $property, $nextDate);
            $property->update(['business_date' => $nextDate]);
            $close->update(['completed_at' => now()]);
            Audit::record($actor, 'closed', $close, 'Business date closed', ['business_date' => $date]);

            return $close;
        }, attempts: 3));
    }

    /** Once per reservation and date: the close is one transaction and a completed date never reruns. */
    private function recordPriceChange(?User $actor, Reservation $reservation, Posting $charge, string $date): void
    {
        $next = $reservation->priceLines()->whereDate('date', CarbonImmutable::parse($date)->addDay()->toDateString())->first();
        $currency = $next?->getAttribute('currency');
        if ($next === null || ($next->getAttribute('amount') === $charge->unit_price && $currency === $charge->currency->value)) {
            return;
        }
        app(RecordLifecycleEvent::class)->handle($reservation, 'nightly_price_changed', $actor, [
            'date' => $next->getAttribute('date')->toDateString(), 'previous_amount' => $charge->unit_price, 'amount' => $next->getAttribute('amount'), 'currency' => $currency,
        ]);
    }

    /** Unresolved items never stall the close; they go on the next date's list. */
    private function carryExceptions(BusinessDateClose $close, Property $property, string $nextDate): void
    {
        $items = [
            ...NoShowProposal::query()->where('property_id', $property->id)->where('status', 'proposed')->with('reservation:id,reservation_number')->get()
                ->map(fn (NoShowProposal $p) => ['no_show', $p->id, $p->reservation->reservation_number]),
            ...Reservation::query()->where('property_id', $property->id)->where('status', ReservationStatus::CheckedIn)->whereDate('departure', '<', $nextDate)->get()
                ->map(fn (Reservation $r) => ['departure', $r->id, $r->reservation_number]),
            ...CashierShift::query()->where('property_id', $property->id)->where('status', 'open')->with('user:id,name')->get()
                ->map(fn (CashierShift $s) => ['cashier_shift', $s->id, $s->user->name]),
        ];
        foreach ($items as [$kind, $subject, $reference]) {
            ExceptionListItem::query()->create([
                'business_date_close_id' => $close->id, 'business_date' => $nextDate,
                'kind' => $kind, 'subject_id' => $subject, 'reference' => $reference,
            ]);
        }
    }

    private function markStayovers(?User $actor, Property $property, string $nextDate): void
    {
        if (! $property->stayover_service_on_close) {
            return;
        }
        $statuses = RoomStatus::query()->with('room')->where('property_id', $property->id)->where('status', HousekeepingStatus::Occupied)->get();
        foreach ($statuses as $status) {
            app(MarkStayoverService::class)->handle($actor, $status->room, $nextDate);
        }
    }
}
