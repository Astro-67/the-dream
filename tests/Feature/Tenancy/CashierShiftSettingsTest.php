<?php

use App\Domains\Billing\Actions\CloseCashierShift;
use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Enums\CashierShiftStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PropertyForm;

test('cashier shifts cannot be disabled until every open shift at that property is closed', function () {
    [$owner, , $property] = organizationWithOwner();
    $shift = inTenant($property, fn () => app(OpenCashierShift::class)->handle($owner, ['USD' => 0]));
    $payload = PropertyForm::payload([
        '_property' => $property->id, 'payment_settings' => ['use_cashier_shifts' => false],
    ]);
    $this->actingAs($owner)->put(route('properties.update', $property), $payload)
        ->assertSessionHasErrors('payment_settings.use_cashier_shifts');
    expect($property->fresh()->settings()['payment_settings']['use_cashier_shifts'])->toBeTrue()
        ->and(inTenant($property, fn () => $shift->fresh()->status))->toBe(CashierShiftStatus::Open)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $property->id)->exists())->toBeTrue();

    inTenant($property, fn () => app(CloseCashierShift::class)->handle($owner, $shift, ['USD' => 0], null));
    $this->put(route('properties.update', $property), $payload)->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page
        ->where('property.payment_settings.use_cashier_shifts', false)
        ->where('canManageCashierShifts', true));
    $payload['payment_settings']['use_cashier_shifts'] = true;
    $this->put(route('properties.update', $property), $payload)->assertSessionHasNoErrors();
    $next = inTenant($property, fn () => app(OpenCashierShift::class)->handle($owner, ['USD' => 0]));
    expect($next->id)->not->toBe($shift->id)
        ->and(inTenant($property, fn () => $shift->fresh()->status))->toBe(CashierShiftStatus::Closed);
});

test('only owners can change the cashier shift preference while managers keep ordinary property editing', function () {
    $t = twoTenants();
    $property = $t->a->property;
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->put(route('properties.update', $property), PropertyForm::payload([
            '_property' => $property->id, 'payment_settings' => ['use_cashier_shifts' => false],
        ]))->assertForbidden();
    expect($property->fresh()->settings()['payment_settings']['use_cashier_shifts'])->toBeTrue();
    $this->put(route('properties.update', $property), PropertyForm::payload([
        '_property' => $property->id, 'payment_settings' => ['deposit_percentage' => 25],
    ]))->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page
        ->where('canManageCashierShifts', false)
        ->where('property.payment_settings.deposit_percentage', 25));
});

test('the disable guard checks the requested property rather than another property in the same organization', function () {
    $t = twoTenants();
    $other = $t->a->otherProperty;
    inTenant($other, fn () => app(OpenCashierShift::class)->handle($t->a->owner, ['USD' => 0]));
    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $payload = PropertyForm::payload([
        '_property' => $t->a->property->id, 'payment_settings' => ['use_cashier_shifts' => false],
    ]);
    $this->put(route('properties.update', $t->a->property), $payload)->assertSessionHasNoErrors();
    $this->put(route('properties.update', $other), $payload)->assertSessionHasErrors('payment_settings.use_cashier_shifts');
    expect($other->fresh()->settings()['payment_settings']['use_cashier_shifts'])->toBeTrue();
});

test('shift preference backfill preserves explicit choices and enables properties with any shift history', function (string $history, ?array $settings, bool $expected) {
    [$owner, , $property] = organizationWithOwner();
    if ($history !== 'none') {
        inTenant($property, function () use ($owner, $history) {
            $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 0]);
            if ($history === 'closed') {
                app(CloseCashierShift::class)->handle($owner, $shift, ['USD' => 0], null);
            }
        });
    }
    $property->update(['payment_settings' => $settings]);
    $migration = require database_path('migrations/2026_10_31_000107_billing_optional_cashier_shifts.php');
    $migration->up();
    $migration->up();
    $migration->down();
    expect($property->fresh()->settings()['payment_settings']['use_cashier_shifts'])->toBe($expected);
    if (isset($settings['deposit_percentage'])) {
        expect($property->fresh()->settings()['payment_settings']['deposit_percentage'])->toBe(25);
    }
    $this->actingAs($owner)->get(route('cashier-shifts.index'))->assertInertia(fn (Assert $page) => $page
        ->has('shifts.data', $history === 'none' ? 0 : 1));
})->with([
    ['none', null, false],
    ['none', [], false],
    ['none', ['deposit_percentage' => 25], false],
    ['open', null, true],
    ['closed', [], true],
    ['closed', ['deposit_percentage' => 25], true],
    ['closed', ['use_cashier_shifts' => false], false],
    ['none', ['use_cashier_shifts' => true], true],
]);
