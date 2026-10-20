<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Rates\Models\RateRestriction;
use App\Domains\Reservations\Actions\AmendReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\AvailabilitySearch;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Tenancy\Tenancy;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

test('property booking rules refuse creation and amendment by name', function (array $settings, string $message) {
    $this->travelTo(new DateTimeImmutable((($settings['book_ahead_days'] ?? null) === 0 ? '2026-12-09' : '2026-12-10').' 09:00:00 UTC'));
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $settings, $message) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        Tenancy::property()->update(['front_desk_settings' => $settings]);
        expect(fn () => app(CreateReservation::class)->handle($owner, $details))->toThrow(ValidationException::class, $message);
        expect(fn () => app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => 'keep']))->toThrow(ValidationException::class, $message);
        expect(Reservation::query()->count())->toBe(1);
    });
})->with([
    'book ahead' => [['book_ahead_days' => 0], 'Book-ahead'],
    'notice' => [['minimum_notice_hours' => 3], 'Minimum notice'],
    'cutoff' => [['same_day_cutoff_time' => '11:00'], 'Same-day cutoff'],
    'minimum stay' => [['minimum_stay' => 3], 'Minimum stay'],
    'maximum stay' => [['maximum_stay' => 1], 'Maximum stay'],
]);

test('only managers override property booking rules with a recorded reason over HTTP', function () {
    $t = twoTenants();
    $details = inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $t->a->property->update(['front_desk_settings' => ['minimum_stay' => 3]]);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $payload = [...$details, '_property' => $t->a->property->id, 'restriction_override_reason' => 'Returning guest'];
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->postJson(route('reservations.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('restriction_override_reason');
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('reservations.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
    inTenant($t->a->property, function () {
        $reservation = Reservation::query()->sole();
        expect($reservation->getAttribute('restriction_override_reason'))->toBe('Returning guest');
        expect(Activity::query()->where('subject_id', $reservation->id)->where('event', 'created')->sole()->properties->get('restriction_warnings'))->toHaveCount(1);
        expect(InventoryRecord::query()->sum('sold_count'))->toBe(2);
    });
});

test('staff sell only the rounded down room type allowance and availability agrees', function (int $percentage, int $capacity) {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['front_desk_settings' => ['maximum_overbooking_percentage' => $percentage]]);
    inTenant($property, function () use ($owner, $capacity) {
        $details = BookingForm::details($owner);
        app(CreateRoom::class)->handle($owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        for ($i = 0; $i < $capacity; $i++) {
            expect(app(AvailabilitySearch::class)->search($details)[0]['available'])->toBe($capacity - $i);
            app(CreateReservation::class)->handle($owner, $details);
        }
        expect(app(AvailabilitySearch::class)->search($details))->toBe([]);
        expect(fn () => app(CreateReservation::class)->handle($owner, $details))->toThrow(ValidationException::class);
        expect(Reservation::query()->count())->toBe($capacity);
        $reservation = Reservation::query()->firstOrFail();
        app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => 'keep']);
        expect(InventoryRecord::query()->whereDate('date', $details['arrival'])->sole()->sold_count)->toBe($capacity);
    });
})->with([[49, 2], [50, 3], [100, 4]]);

test('booking rule boundaries use the property local time and check-in time', function (string $now, array $settings) {
    $this->travelTo(new DateTimeImmutable($now));
    [$owner, , $property] = organizationWithOwner();
    $property->update(['timezone' => 'Africa/Dar_es_Salaam', 'front_desk_settings' => $settings]);
    inTenant($property, function () use ($owner) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        expect($reservation->status->value)->toBe('confirmed');
    });
})->with([
    ['2026-12-09 21:00:00 UTC', ['book_ahead_days' => 0]],
    ['2026-12-10 08:00:00 UTC', ['minimum_notice_hours' => 3]],
    ['2026-12-10 08:59:00 UTC', ['same_day_cutoff_time' => '12:00']],
    ['2026-12-10 08:00:00 UTC', ['minimum_stay' => 2, 'maximum_stay' => 2]],
]);

test('a manager amendment override is recorded without bypassing rate plan restrictions', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $reservation = app(CreateReservation::class)->handle($owner, $details);
        Tenancy::property()->update(['front_desk_settings' => ['minimum_stay' => 3]]);
        RateRestriction::query()->create(['rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'], 'date' => $details['arrival'], 'min_stay' => 4, 'closed_to_arrival' => false, 'stop_sell' => false]);
        $reservation = app(AmendReservation::class)->handle($owner, $reservation, [...$details, 'room_id' => null, 'pricing' => 'keep', 'restriction_override_reason' => ' Manager exception ']);
        expect($reservation->getAttribute('restriction_override_reason'))->toBe('Manager exception');
        expect($reservation->lifecycleEvents()->where('type', 'modified')->sole()->snapshot['after']['restriction_override_reason'])->toBe('Manager exception');
        expect(Activity::query()->where('subject_id', $reservation->id)->where('event', 'updated')->sole()->properties->get('restriction_warnings'))->toHaveCount(2);
    });
});
