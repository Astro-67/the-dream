<?php

use App\Domains\Billing\Actions\CloseCashierShift;
use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\CashierShiftStatus;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TwoTenants;

function cashierShiftActor(TwoTenants $t, OrganizationRole $role): User
{
    return $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);
}

test('only roles with charges.post open and close their own shift', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $this->actingAs(cashierShiftActor($t, $role));

    $open = $this->post(route('cashier-shifts.store'), ['_property' => $t->a->property->id, 'floats' => ['USD' => 10000, 'TZS' => 0]]);

    $allowed ? $open->assertRedirect(route('cashier-shifts.index')) : $open->assertForbidden();
    expect(inTenant($t->a->property, fn () => CashierShift::query()->count()))->toBe($allowed ? 1 : 0);
    if ($allowed) {
        $shift = inTenant($t->a->property, fn () => CashierShift::query()->sole());
        $this->post(route('cashier-shifts.close', $shift), ['_property' => $t->a->property->id, 'counted' => ['USD' => 10000, 'TZS' => 0]])
            ->assertRedirect(route('cashier-shifts.index'))->assertSessionHasNoErrors();
        expect(inTenant($t->a->property, fn () => $shift->fresh()->status))->toBe(CashierShiftStatus::Closed);
    }
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk, OrganizationRole::Finance], true)], OrganizationRole::cases()));

test('a cashier cannot close another cashier\'s shift', function () {
    $t = twoTenants();
    $shift = inTenant($t->a->property, fn () => app(OpenCashierShift::class)->handle($t->a->member(OrganizationRole::FrontDesk), ['USD' => 100]));

    $this->actingAs($t->a->member(OrganizationRole::Finance))
        ->post(route('cashier-shifts.close', $shift), ['_property' => $t->a->property->id, 'counted' => ['USD' => 100]])->assertForbidden();

    expect(inTenant($t->a->property, fn () => $shift->fresh()->status))->toBe(CashierShiftStatus::Open);
});

test('the close form needs a reason for a variance, and a second open shift is refused', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->post(route('cashier-shifts.store'), ['_property' => $t->a->property->id, 'floats' => ['USD' => 10000]])->assertSessionHasNoErrors();
    $this->post(route('cashier-shifts.store'), ['_property' => $t->a->property->id, 'floats' => ['USD' => 10000]])->assertSessionHasErrors('shift');
    $shift = inTenant($t->a->property, fn () => CashierShift::query()->sole());

    $this->postJson(route('cashier-shifts.close', $shift), ['_property' => $t->a->property->id, 'counted' => ['USD' => -1]])->assertJsonValidationErrors('counted.USD');
    $this->post(route('cashier-shifts.close', $shift), ['_property' => $t->a->property->id, 'counted' => ['USD' => 9000]])->assertSessionHasErrors('variance_reason');
    $this->post(route('cashier-shifts.close', $shift), ['_property' => $t->a->property->id, 'counted' => ['USD' => 9000], 'variance_reason' => 'Short'])->assertSessionHasNoErrors();

    expect(inTenant($t->a->property, fn () => $shift->fresh()->variance()))->toBe(['USD' => -1000]);
});

test('cash over HTTP needs the cashier\'s open shift', function () {
    $t = twoTenants();
    $cashier = $t->a->member(OrganizationRole::FrontDesk);
    $folio = inTenant($t->a->property, function () use ($t) {
        $id = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner))->id;

        return Folio::query()->where('reservation_id', $id)->sole();
    });
    $form = ['_property' => $t->a->property->id, 'method' => 'cash', 'amount' => 2000];
    $this->actingAs($cashier);

    $this->post(route('folios.payments.store', $folio), [...$form, 'idempotency_key' => 'a'])->assertSessionHasErrors('method');
    $this->post(route('cashier-shifts.store'), ['_property' => $t->a->property->id, 'floats' => ['USD' => 0]]);
    $this->post(route('folios.payments.store', $folio), [...$form, 'idempotency_key' => 'b'])->assertSessionHasNoErrors()->assertRedirect();

    expect(inTenant($t->a->property, fn () => CashierShift::query()->sole()->expectedCash()))->toBe(['USD' => 2000]);
});

test('finance sees the shift list with expected, counted and variance per currency', function (OrganizationRole $role, string $access) {
    $t = twoTenants();
    $cashier = $t->a->member(OrganizationRole::FrontDesk);
    inTenant($t->a->property, function () use ($t, $cashier) {
        $id = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner))->id;
        $shift = app(OpenCashierShift::class)->handle($cashier, ['USD' => 10000, 'TZS' => 20000]);
        app(RecordPayment::class)->handle($cashier, Folio::query()->where('reservation_id', $id)->sole(), ['method' => 'cash', 'amount' => 3000, 'currency' => 'USD']);
        app(CloseCashierShift::class)->handle($cashier, $shift, ['USD' => 12000, 'TZS' => 20000], 'Change error');
    });

    $response = $this->actingAs(cashierShiftActor($t, $role))->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->get(route('cashier-shifts.index'));

    if ($access === 'forbidden') {
        $response->assertForbidden();

        return;
    }
    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('cashier-shifts/index')
        ->when($access === 'list', fn (Assert $page) => $page
            ->where('shifts.data.0.cashier', $cashier->name)->where('shifts.data.0.status', 'closed')
            ->where('shifts.data.0.variance_reason', 'Change error')
            ->where('shifts.data.0.currencies', [
                ['currency' => 'TZS', 'float' => 20000, 'expected' => 20000, 'counted' => 20000, 'variance' => 0],
                ['currency' => 'USD', 'float' => 10000, 'expected' => 13000, 'counted' => 12000, 'variance' => -1000],
            ]))
        ->when($access === 'own', fn (Assert $page) => $page->where('shifts', null)));
})->with(array_map(fn (OrganizationRole $role) => [$role, match ($role) {
    OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::Finance, OrganizationRole::Auditor => 'list',
    OrganizationRole::FrontDesk, OrganizationRole::PropertyManager => 'own',
    default => 'forbidden',
}], OrganizationRole::cases()));

test('a cashier sees their own open shift with what Thedream expects', function () {
    $t = twoTenants();
    $cashier = $t->a->member(OrganizationRole::FrontDesk);
    inTenant($t->a->property, fn () => app(OpenCashierShift::class)->handle($cashier, ['USD' => 10000]));

    $this->actingAs($cashier)->get(route('cashier-shifts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canOpenShift', true)
        ->where('openShift.currencies', [['currency' => 'USD', 'float' => 10000, 'expected' => 10000, 'counted' => null, 'variance' => null]])
        ->where('currencies', ['TZS', 'USD', 'EUR']));
});
