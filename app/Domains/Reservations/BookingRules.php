<?php

namespace App\Domains\Reservations;

use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BookingRules
{
    /** @return list<string> */
    public function warnings(Property $property, string $arrival, string $departure): array
    {
        $settings = $property->settings()['front_desk_settings'];
        $now = CarbonImmutable::now($property->timezone);
        $checkIn = CarbonImmutable::parse($arrival.' '.$settings['default_check_in_time'], $property->timezone);
        $nights = (int) $checkIn->startOfDay()->diffInDays(CarbonImmutable::parse($departure, $property->timezone));
        $warnings = [];
        if ($arrival > $now->addDays($settings['book_ahead_days'])->toDateString()) {
            $warnings[] = __('Book-ahead limit is :days days.', ['days' => $settings['book_ahead_days']]);
        }
        if ($settings['minimum_notice_hours'] > 0 && $checkIn->lt($now->addHours($settings['minimum_notice_hours']))) {
            $warnings[] = __('Minimum notice is :hours hours before check-in.', ['hours' => $settings['minimum_notice_hours']]);
        }
        if ($arrival === $now->toDateString() && $settings['same_day_cutoff_time'] !== null && $now->format('H:i') >= $settings['same_day_cutoff_time']) {
            $warnings[] = __('Same-day cutoff is :time.', ['time' => $settings['same_day_cutoff_time']]);
        }
        if ($nights < $settings['minimum_stay']) {
            $warnings[] = __('Minimum stay is :nights nights.', ['nights' => $settings['minimum_stay']]);
        }
        if ($nights > $settings['maximum_stay']) {
            $warnings[] = __('Maximum stay is :nights nights.', ['nights' => $settings['maximum_stay']]);
        }

        return $warnings;
    }

    /** @param list<string> $warnings */
    public function authorizeOverride(?User $actor, array $warnings): void
    {
        if ($warnings !== [] && ! ($actor?->can('overrideBookingRules', Reservation::class) ?? false)) {
            throw ValidationException::withMessages(['restriction_override_reason' => [...$warnings, __('A manager must override booking rules with a reason.')]]);
        }
    }
}
