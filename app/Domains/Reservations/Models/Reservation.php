<?php

namespace App\Domains\Reservations\Models;

use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Reservations\Enums\MealPlan;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Enums\TourOperatorPaysFor;
use App\Domains\Reservations\Policies\ReservationPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $property_id
 * @property string $reservation_number
 * @property CarbonImmutable $arrival
 * @property CarbonImmutable $departure
 * @property ReservationStatus $status
 * @property string|null $room_id
 * @property string $payment_collector
 * @property int|null $channel_collected_amount minor units, when the channel collects
 */
#[Fillable(['meal_plan', 'special_requests', 'booked_through', 'tour_operator_name', 'tour_operator_registration', 'tour_operator_pays_for', 'booking_group_id', 'reservation_number', 'room_type_id', 'rate_plan_id', 'booker_id', 'arrival', 'departure', 'adults', 'children', 'source', 'source_reference', 'imported', 'payment_collector', 'status', 'room_id', 'restriction_override_reason', 'channel_reference', 'outside_integration_reason', 'requires_reconciliation', 'channel_collected_amount'])]
#[UsePolicy(ReservationPolicy::class)]
class Reservation extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meal_plan' => MealPlan::class, 'tour_operator_pays_for' => TourOperatorPaysFor::class, 'imported' => 'boolean', 'requires_reconciliation' => 'boolean', 'arrival' => 'immutable_date', 'departure' => 'immutable_date', 'adults' => 'integer', 'children' => 'integer', 'channel_collected_amount' => 'integer', 'status' => ReservationStatus::class];
    }

    public function leadGuest(): ?Guest
    {
        $link = ReservationGuest::query()->where('reservation_id', $this->id)->oldest('id')->first();

        return $link === null ? null : Guest::query()->find($link->getAttribute('guest_id'));
    }

    /**
     * Each reservation's guest name in two queries: the lead guest, or the booker for stays
     * booked before the lead guest was taken inline.
     *
     * @param  Collection<int, covariant self>  $reservations
     * @return Collection<string, string|null> keyed by reservation id
     */
    public static function guestNames(Collection $reservations): Collection
    {
        $links = ReservationGuest::query()->whereIn('reservation_id', $reservations->pluck('id'))->orderBy('id')->get(['reservation_id', 'guest_id'])->unique('reservation_id');
        $names = Guest::query()->whereIn('id', $links->pluck('guest_id'))->pluck('name', 'id');
        $bookers = Booker::query()->whereIn('id', $reservations->pluck('booker_id')->filter())->pluck('name', 'id');
        $leads = $links->mapWithKeys(fn (ReservationGuest $link) => [$link->getAttribute('reservation_id') => $names->get($link->getAttribute('guest_id'))]);

        return $reservations->mapWithKeys(fn (Reservation $reservation) => [$reservation->id => $leads->get($reservation->id) ?? $bookers->get($reservation->getAttribute('booker_id'))]);
    }

    /**
     * The guest-safe stay details a statement or receipt shows: no identity documents or notes.
     *
     * @return array{reservation_number: string, guest: string, room: string|null, room_type: string|null, arrival: string, departure: string, nights: int}
     */
    public function stay(): array
    {
        return [
            'reservation_number' => $this->reservation_number, 'guest' => self::guestNames(collect([$this]))->get($this->id) ?? __('Guest'),
            'room' => Room::query()->find($this->room_id)?->number, 'room_type' => RoomType::query()->find($this->getAttribute('room_type_id'))?->name,
            'arrival' => $this->arrival->toDateString(), 'departure' => $this->departure->toDateString(), 'nights' => (int) $this->arrival->diffInDays($this->departure),
        ];
    }

    public function isOverstay(string $businessDate): bool
    {
        return $this->status === ReservationStatus::CheckedIn && $this->departure->toDateString() < $businessDate;
    }

    public function getRouteKeyName(): string
    {
        return 'reservation_number';
    }

    /** @return HasMany<ReservationPriceLine, $this> */
    public function priceLines(): HasMany
    {
        return $this->hasMany(ReservationPriceLine::class)->orderBy('date');
    }

    /**
     * The price line for a night of the stay. Price lines end before departure; a night at
     * or after departure (a departure not yet checked out) repeats the last night's price.
     */
    public function nightPrice(string $date): ?ReservationPriceLine
    {
        return $this->priceLines()->whereDate('date', $date)->first()
            ?? ($date >= $this->departure->toDateString()
                ? $this->priceLines()->whereDate('date', '<', $this->departure->toDateString())->reorder()->latest('date')->first()
                : null);
    }

    /** @return HasMany<ReservationLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ReservationLifecycleEvent::class)->orderBy('sequence');
    }
}
