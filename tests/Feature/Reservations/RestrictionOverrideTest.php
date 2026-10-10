<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RateRestriction;
use App\Domains\Rates\StayRestrictions;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

test('staff sees every restriction and submits an override through the booking form', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => $details['arrival'], 'min_stay' => 3, 'closed_to_arrival' => true, 'stop_sell' => true]);

        return $details;
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner)->get(route('reservations.create', $details))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('restrictionWarnings', 3));
    $this->postJson(route('reservations.store'), [...$details, '_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors('restriction_override_reason');
    $response = $this->post(route('reservations.store'), [...$details, '_property' => $t->a->property->id, 'restriction_override_reason' => 'Returning guest exception']);
    $response->assertRedirect();
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('reservation.restriction_override_reason', 'Returning guest exception'));
});

test('staff restriction override is required persisted and audited', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => $details['arrival'], 'min_stay' => 3, 'closed_to_arrival' => true, 'stop_sell' => true]);
        foreach ([null, '', '   '] as $reason) {
            expect(fn () => app(CreateReservation::class)->handle($owner, [...$details, 'restriction_override_reason' => $reason]))->toThrow(ValidationException::class);
        }
        expect(Reservation::query()->count())->toBe(0)->and(InventoryRecord::query()->sum('sold_count'))->toBe(0);
        $reservation = app(CreateReservation::class)->handle($owner, [...$details, 'restriction_override_reason' => 'Returning guest exception']);
        expect($reservation->fresh()->getAttribute('restriction_override_reason'))->toBe('Returning guest exception');
        $audit = Activity::query()->where('subject_id', $reservation->id)->where('event', 'created')->sole();
        expect($audit->causer_id)->toBe($owner->id)
            ->and($audit->properties->get('restriction_override_reason'))->toBe('Returning guest exception')
            ->and($audit->properties->get('restriction_warnings'))->toHaveCount(3);
    });
});

test('stay restrictions list arrival rules and stop sell on each occupied night', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => '2026-12-10', 'min_stay' => 3, 'closed_to_arrival' => true, 'stop_sell' => true]);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => '2026-12-11', 'min_stay' => 5, 'closed_to_arrival' => true, 'stop_sell' => true]);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => '2026-12-12', 'min_stay' => 9, 'closed_to_arrival' => true, 'stop_sell' => true]);
        expect(app(StayRestrictions::class)->warnings(RatePlan::query()->findOrFail($details['rate_plan_id']), RoomType::query()->findOrFail($details['room_type_id']), $details['arrival'], $details['departure']))->toBe([
            'Minimum stay on 2026-12-10 is 3 nights; this stay is 2 nights.',
            'Closed to arrival on 2026-12-10.',
            'Stop-sell on 2026-12-10.',
            'Stop-sell on 2026-12-11.',
        ]);
    });
});
