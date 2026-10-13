<?php

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\BookingForm;

/**
 * A 2-night stay arriving 2026-12-10 at 11000 a night (base 10000, one child 1000),
 * on a plan with the given policy.
 *
 * @param  array<string, mixed>  $policy
 * @return array{User, Reservation}
 */
function bookedWithPolicy(array $policy): array
{
    [$owner, , $property] = organizationWithOwner();

    return [$owner, inTenant($property, function () use ($owner, $policy) {
        $details = BookingForm::details($owner);
        RatePlan::query()->whereKey($details['rate_plan_id'])->update($policy);

        return app(CreateReservation::class)->handle($owner, $details);
    })];
}

function cancelOn(User $owner, Reservation $reservation, string $localDate): ?FeeProposal
{
    $property = Property::query()->withoutGlobalScopes()->findOrFail($reservation->property_id);
    test()->travelTo(CarbonImmutable::parse($localDate.' 23:30', $property->timezone));

    return inTenant($property, function () use ($owner, $reservation) {
        app(CancelReservation::class)->handle($owner, $reservation, 'Guest changed plans');

        return FeeProposal::query()->where('reservation_id', $reservation->id)->first();
    });
}

test('cancelling proposes the fee the policy gives for the property local date', function (array $policy, string $cancelledOn, ?int $fee) {
    [$owner, $reservation] = bookedWithPolicy($policy);

    $proposal = cancelOn($owner, $reservation, $cancelledOn);

    expect($proposal?->amount)->toBe($fee);
    if ($fee !== null) {
        expect($proposal->kind)->toBe('cancellation')->and($proposal->currency->value)->toBe('USD')->and($proposal->status)->toBe('proposed');
    }
})->with([
    'percent, inside the free window' => [['cancellation_free_days' => 7, 'cancellation_charge' => 'percent', 'cancellation_percent' => 25], '2026-12-03', null],
    'percent, outside the free window' => [['cancellation_free_days' => 7, 'cancellation_charge' => 'percent', 'cancellation_percent' => 25], '2026-12-04', 5500],
    'first night, inside the free window' => [['cancellation_free_days' => 2, 'cancellation_charge' => 'first_night'], '2026-12-08', null],
    'first night, outside the free window' => [['cancellation_free_days' => 2, 'cancellation_charge' => 'first_night'], '2026-12-09', 11000],
    'full stay, inside the free window' => [['cancellation_free_days' => 30, 'cancellation_charge' => 'full_stay'], '2026-11-10', null],
    'full stay, outside the free window' => [['cancellation_free_days' => 30, 'cancellation_charge' => 'full_stay'], '2026-11-11', 22000],
    'never free' => [['cancellation_charge' => 'first_night'], '2026-01-01', 11000],
    'no policy' => [[], '2026-12-09', null],
]);
