<?php

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TenantFixture;

/** @return array<string, string> */
function vfmsCredentials(TenantFixture $tenant, array $overrides = []): array
{
    return [
        '_property' => $tenant->property->id,
        'integration_id' => 'INT-1001',
        'registration_id' => 'REG-2002',
        'token' => 'tok-secret',
        ...$overrides,
    ];
}

// protects=only Owner and Property manager hold VFMS credentials (spec #156); fails_when=another role saves them or the managers are refused; no_claim=what the page shows.
test('only the owner and property manager save VFMS credentials', function (OrganizationRole $role, bool $manages) {
    $t = twoTenants();
    $member = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($member)->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a));

    $manages ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertForbidden();
    $this->actingAs($t->a->owner)->get(route('properties.edit', $t->a->property))
        ->assertInertia(fn (Assert $page) => $page->where('vfms.integration_id', $manages ? 'INT-1001' : null));
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'property manager' => [OrganizationRole::PropertyManager, true],
    'group operations manager' => [OrganizationRole::GroupOperationsManager, false],
    'front desk' => [OrganizationRole::FrontDesk, false],
    'finance' => [OrganizationRole::Finance, false],
    'housekeeping' => [OrganizationRole::Housekeeping, false],
    'auditor' => [OrganizationRole::Auditor, false],
]);

// protects=the token is write-only (spec #156 AC 2); fails_when=a saved or refused token shows in a page, a log line or an audit entry; no_claim=outbox payloads (no VFMS messages exist yet).
test('a saved token never comes back in a page, a log line or an audit entry', function () {
    $t = twoTenants();
    $marker = 'MARKER-TOKEN-7f3a';
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
        $logged[] = $log->message.json_encode($log->context);
    });
    $this->actingAs($t->a->owner);

    $this->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a, ['token' => $marker]))->assertSessionHasNoErrors();
    $this->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a, ['token' => $marker, 'integration_id' => '']))->assertSessionHasErrors('integration_id');
    $flashed = json_encode(session()->all());
    $this->put(route('properties.vfms.mode', $t->a->property), ['_property' => $t->a->property->id, 'mode' => 'live', 'confirmation' => 'LIVE', 'reason' => 'Pilot go-live'])->assertSessionHasNoErrors();
    $page = $this->get(route('properties.edit', $t->a->property))
        ->assertInertia(fn (Assert $page) => $page->where('vfms.token_saved', true)->where('vfms.mode', 'live'));

    expect($page->getContent())->not->toContain($marker)
        ->and($flashed)->not->toContain($marker)
        ->and(Activity::query()->get()->toJson())->not->toContain($marker)
        ->and(implode("\n", $logged))->not->toContain($marker)
        ->and(VfmsCredential::for($t->a->property)->token)->toBe($marker);
});

// protects=a blank token keeps the saved one, so editing the IDs never needs the token again; fails_when=a blank token erases the saved token or a first save without one passes; no_claim=token validity with VFMS.
test('a blank token keeps the saved token, and a first save needs one', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner);

    $this->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a, ['token' => '']))->assertSessionHasErrors('token');
    $this->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a))->assertSessionHasNoErrors();
    $this->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a, ['integration_id' => 'INT-9', 'token' => '']))->assertSessionHasNoErrors();

    $credential = VfmsCredential::for($t->a->property);
    expect($credential->integration_id)->toBe('INT-9')->and($credential->token)->toBe('tok-secret');
});

// protects=every property starts on Sandbox and stays there until someone switches (spec #156); fails_when=a fresh or migrated property reads Live; no_claim=delivery behaviour per mode.
test('a property with nothing saved is on Sandbox', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)->get(route('properties.edit', $t->a->property))
        ->assertInertia(fn (Assert $page) => $page->where('vfms.mode', 'sandbox')->where('vfms.token_saved', false)->where('vfms.integration_id', null));
    expect(VfmsCredential::for($t->a->property)->mode)->toBe(VfmsMode::Sandbox);
});

// protects=switching to Live is deliberate (spec #156); fails_when=Live is reached without all three credentials, a typed confirmation or a reason, or at a mainland property; no_claim=switching back is covered separately.
test('switching to Live is refused without credentials, confirmation or reason, or at a mainland property', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner);
    $live = fn (array $overrides = [], $property = null) => $this->put(route('properties.vfms.mode', $property ?? $t->a->property), [
        '_property' => ($property ?? $t->a->property)->id, 'mode' => 'live', 'confirmation' => 'LIVE', 'reason' => 'Pilot go-live', ...$overrides,
    ]);

    $live()->assertSessionHasErrors(['mode' => 'Save the Integration ID, Registration ID and Token before switching to Live.']);
    $this->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a))->assertSessionHasNoErrors();
    $live(['confirmation' => ''])->assertSessionHasErrors('confirmation');
    $live(['confirmation' => 'live please'])->assertSessionHasErrors('confirmation');
    $live(['reason' => ''])->assertSessionHasErrors('reason');
    $live([], $t->a->otherProperty)->assertSessionHasErrors(['mode' => 'This property does not use VFMS.']);

    expect(VfmsCredential::for($t->a->property)->mode)->toBe(VfmsMode::Sandbox)
        ->and(VfmsCredential::for($t->a->otherProperty)->exists)->toBeFalse()
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $t->a->property->id)->count())->toBe(4);
});

// protects=every mode change names who and why (spec #156); fails_when=a switch to Live or back to Sandbox is not audited with actor and reason; no_claim=refusals.
test('switching to Live and back to Sandbox is audited with actor and reason', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);
    $this->actingAs($manager);
    $this->put(route('properties.vfms.update', $t->a->property), vfmsCredentials($t->a))->assertSessionHasNoErrors();
    $switch = fn (string $mode, string $reason) => $this->put(route('properties.vfms.mode', $t->a->property), ['_property' => $t->a->property->id, 'mode' => $mode, 'confirmation' => 'LIVE', 'reason' => $reason]);

    $this->freezeTime();
    $switch('live', 'Pilot go-live')->assertSessionHasNoErrors();
    expect(VfmsCredential::for($t->a->property)->live_since?->toIso8601String())->toBe(now()->toIso8601String());
    $switch('sandbox', 'Wrong receipts in testing')->assertSessionHasNoErrors();

    $changes = Activity::query()->where('event', 'vfms_mode_changed')->orderBy('id')->get();
    expect($changes)->toHaveCount(2)
        ->and($changes->pluck('causer_id')->unique()->all())->toBe([$manager->id])
        ->and($changes->map(fn (Activity $a) => [$a->getExtraProperty('attributes')['mode'], $a->getExtraProperty('reason')])->all())
        ->toBe([['live', 'Pilot go-live'], ['sandbox', 'Wrong receipts in testing']])
        ->and(VfmsCredential::for($t->a->property)->mode)->toBe(VfmsMode::Sandbox)
        ->and(VfmsCredential::for($t->a->property)->live_since)->toBeNull();
});

// protects=mainland properties never see VFMS settings (ADR 0014); fails_when=the edit page sends VFMS settings for a mainland property or saving credentials there passes; no_claim=the page layout.
test('a mainland property has no VFMS settings', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner);

    $this->get(route('properties.edit', $t->a->otherProperty))->assertInertia(fn (Assert $page) => $page->where('vfms', null));
    $this->put(route('properties.vfms.update', $t->a->otherProperty), vfmsCredentials($t->a, ['_property' => $t->a->otherProperty->id]))
        ->assertSessionHasErrors(['vfms' => 'This property does not use VFMS.']);
});
