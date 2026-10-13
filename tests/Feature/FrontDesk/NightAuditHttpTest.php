<?php

use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;

test('a property manager starts night audit and reads the room charge summary', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['business_date' => '2026-12-10']);
    $reservation = inTenant($property, function () use ($tenants) {
        $reservation = app(CreateReservation::class)->handle($tenants->a->owner, BookingForm::details($tenants->a->owner));
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        return $reservation;
    });
    $actor = $tenants->a->member(OrganizationRole::PropertyManager);
    $response = $this->actingAs($actor)->post(route('night-audit.store'), ['_property' => $property->id, 'business_date' => '2026-12-10']);
    $close = inTenant($property, fn () => BusinessDateClose::query()->sole());
    $response->assertRedirect(route('night-audit.show', $close));
    $this->get(route('night-audit.show', $close))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('night-audit/show')
        ->where('close.business_date', '2026-12-10')
        ->where('close.actor', $actor->name)
        ->has('charges.data', 1)
        ->where('charges.data.0.reservation_number', $reservation->reservation_number)
        ->where('charges.data.0.amount', 11000)
        ->where('charges.data.0.currency', 'USD'));
});

test('staff without the close permission cannot start night audit', function (OrganizationRole $role) {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['business_date' => '2026-12-10']);
    $this->actingAs($tenants->a->member($role))
        ->postJson(route('night-audit.store'), ['_property' => $property->id, 'business_date' => '2026-12-10'])
        ->assertForbidden();
    inTenant($property, fn () => expect(BusinessDateClose::query()->count())->toBe(0)
        ->and(Posting::query()->count())->toBe(0));
    expect($property->fresh()->business_date->toDateString())->toBe('2026-12-10');
})->with([OrganizationRole::FrontDesk, OrganizationRole::Finance, OrganizationRole::Housekeeping, OrganizationRole::Auditor]);

test('night audit lists closes and offers closing only to managers', function (OrganizationRole $role, bool $canClose) {
    $tenants = twoTenants();
    $this->actingAs($tenants->a->member($role))->get(route('night-audit.index'))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('night-audit/index')->where('canClose', $canClose)->has('closes.data', 0));
})->with([
    [OrganizationRole::PropertyManager, true],
    [OrganizationRole::GroupOperationsManager, true],
    [OrganizationRole::FrontDesk, false],
    [OrganizationRole::Finance, false],
    [OrganizationRole::Auditor, false],
]);

test('night audit refuses malformed or non-current business dates without opening a new date', function (string $date) {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['business_date' => '2026-12-10']);
    $this->actingAs($tenants->a->owner)
        ->postJson(route('night-audit.store'), ['_property' => $property->id, 'business_date' => $date])
        ->assertUnprocessable()->assertJsonValidationErrors('business_date');
    expect($property->fresh()->business_date->toDateString())->toBe('2026-12-10');
    inTenant($property, fn () => expect(BusinessDateClose::query()->count())->toBe(0));
})->with(['2026-12-09', '2026-12-11', '2026-02-30', 'not-a-date']);
