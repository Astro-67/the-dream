<?php

use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\NoShowProposalStatus;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

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

test('the close summary lists its no-show proposals and a manager confirms or rejects each', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['business_date' => '2026-12-10']);
    [$first, $second] = inTenant($property, function () use ($tenants) {
        $details = BookingForm::details($tenants->a->owner);
        app(CreateRoom::class)->handle($tenants->a->owner, [...InventoryForm::room($details['room_type_id']), 'number' => '2']);

        return [app(CreateReservation::class)->handle($tenants->a->owner, $details), app(CreateReservation::class)->handle($tenants->a->owner, $details)];
    });
    $manager = $tenants->a->member(OrganizationRole::PropertyManager);
    $this->actingAs($manager)->post(route('night-audit.store'), ['_property' => $property->id, 'business_date' => '2026-12-10']);
    [$close, $proposal, $other] = inTenant($property, fn () => [BusinessDateClose::query()->sole(),
        NoShowProposal::query()->where('reservation_id', $first->id)->sole(), NoShowProposal::query()->where('reservation_id', $second->id)->sole()]);

    $this->get(route('night-audit.show', $close))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('noShowsProposed', 2)->has('noShows', 2)
        ->where('noShows.0.reservation_number', $first->reservation_number)
        ->where('noShows.0.status', 'proposed')->where('noShows.0.can_resolve', true));
    $this->from(route('night-audit.show', $close))->post(route('no-show-proposals.confirm', $proposal), ['_property' => $property->id])
        ->assertRedirect(route('night-audit.show', $close));
    $this->post(route('no-show-proposals.reject', $other), ['_property' => $property->id]);

    inTenant($property, fn () => expect($first->fresh()->status)->toBe(ReservationStatus::NoShow)
        ->and($second->fresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($other->fresh()->status)->toBe(NoShowProposalStatus::Rejected));
});

test('staff without the close permission cannot confirm or reject a no-show', function (OrganizationRole $role) {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['business_date' => '2026-12-10']);
    $proposal = inTenant($property, function () use ($tenants, $property) {
        app(CreateReservation::class)->handle($tenants->a->owner, BookingForm::details($tenants->a->owner));
        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');

        return NoShowProposal::query()->sole();
    });
    $this->actingAs($tenants->a->member($role));
    foreach (['confirm', 'reject'] as $decision) {
        $this->post(route('no-show-proposals.'.$decision, $proposal), ['_property' => $property->id])->assertForbidden();
    }
    $refusals = inTenant($property, fn () => Activity::query()->where('description', 'Sensitive form refused')->get());
    expect($refusals)->toHaveCount(2)
        ->and($refusals->pluck('subject_type')->unique()->all())->toBe([$proposal->getMorphClass()])
        ->and($refusals->pluck('subject_id')->unique()->all())->toBe([$proposal->id]);
    inTenant($property, fn () => expect($proposal->fresh()->status)->toBe(NoShowProposalStatus::Proposed)
        ->and($proposal->reservation->status)->toBe(ReservationStatus::Confirmed));
})->with([OrganizationRole::FrontDesk, OrganizationRole::Finance, OrganizationRole::Housekeeping, OrganizationRole::Auditor]);

test('the manager sees the current date exception list and the close summary counts what it carried', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['business_date' => '2026-12-10']);
    $reservation = inTenant($property, fn () => app(CreateReservation::class)->handle($tenants->a->owner, BookingForm::details($tenants->a->owner)));
    $manager = $tenants->a->member(OrganizationRole::PropertyManager);
    $this->actingAs($manager)->post(route('night-audit.store'), ['_property' => $property->id, 'business_date' => '2026-12-10']);
    $close = inTenant($property, fn () => BusinessDateClose::query()->sole());

    $this->get(route('night-audit.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('exceptions', 1)->where('exceptions.0.kind', 'no_show')
        ->where('exceptions.0.reference', $reservation->reservation_number));
    $this->get(route('night-audit.show', $close))->assertInertia(fn (AssertableInertia $page) => $page->where('exceptionsCarried', 1));
});
