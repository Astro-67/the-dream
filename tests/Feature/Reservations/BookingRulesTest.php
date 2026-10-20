<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\AmendReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Tenancy\Tenancy;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

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
