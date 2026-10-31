<?php

use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\SaasPaymentSlip;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Models\Organization;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InventoryForm;

test('a platform administrator opens an organization with its owners and subscription', function () {
    $tenants = twoTenants();
    inTenant($tenants->a->organization, fn () => Subscription::current()->update([
        'status' => 'active', 'current_period_ends_at' => '2026-12-01 00:00:00',
    ]));

    $admin = platformStaff();
    $this->actingAs($admin)
        ->get(route('platform.organizations.show', $tenants->a->organization->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/organizations/show')
            ->where('organization.id', $tenants->a->organization->id)
            ->where('organization.name', 'Alpha Hotels Group')
            ->where('organization.owners', [['name' => 'Alpha Owner', 'email' => 'owner@alpha.test']])
            ->where('subscription.plan', 'Essential')
            ->where('subscription.status', 'active')
            ->where('subscription.currentPeriodEndsAt', '2026-12-01T00:00:00+00:00')
            ->where('tenant.organization', null)
            ->where('tenant.property', null));

    $entry = Activity::query()->where('log_name', 'platform')->where('event', 'platform_context_entered')->sole();
    expect($entry->causer?->is($admin))->toBeTrue()
        ->and($entry->getExtraProperty('reason'))->toBe('Platform administrator viewed organization '.$tenants->a->organization->id);
});

test('only platform administrators with two-factor authentication can open organization details', function () {
    $tenants = twoTenants();
    $url = route('platform.organizations.show', $tenants->b->organization->id);
    foreach ($tenants->a->members() as $member) {
        $this->actingAs($member)->get($url)->assertForbidden();
    }
    $this->actingAs(platformStaff(PlatformRole::ComplianceOperator))->get($url)->assertForbidden();
    $this->actingAs(platformStaff(twoFactor: false))->get($url)->assertRedirect(route('security.edit'));
});

test('missing organizations return not found and empty organizations render safely', function () {
    $this->actingAs(platformStaff())->get(route('platform.organizations.show', 'missing'))->assertNotFound();
    $organization = Tenancy::within(
        TenantContext::platform('Seed an empty organization'),
        fn () => Organization::factory()->create(),
    );

    $this->get(route('platform.organizations.show', $organization->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organization.owners', [])
            ->where('subscription', null)
            ->has('invoices.data', 0)
            ->has('properties', 0));
});

test('the organization page shows only its invoices and recorded payments', function () {
    withBillingDetails();
    $tenants = twoTenants();
    $invoice = inTenant($tenants->a->organization, function () {
        $invoice = app(IssueSaasInvoice::class)->handle(Subscription::current());
        SaasPayment::query()->create([
            'saas_invoice_id' => $invoice->id, 'amount' => 1900, 'method' => 'bank_transfer',
            'reference' => 'ALPHA-BANK-001', 'received_on' => '2026-10-31',
        ]);

        return $invoice;
    });
    inTenant($tenants->b->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));

    $this->actingAs(platformStaff())->get(route('platform.organizations.show', $tenants->a->organization->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $invoice->id)
            ->where('invoices.data.0.status', 'open')
            ->where('invoices.data.0.amount', 735000)
            ->where('invoices.data.0.balance', 733100)
            ->where('invoices.data.0.currency', 'USD')
            ->has('invoices.data.0.payments.data', 1)
            ->where('invoices.data.0.payments.data.0.amount', 1900)
            ->where('invoices.data.0.payments.data.0.reference', 'ALPHA-BANK-001')
            ->where('invoices.data.0.payments.data.0.method', 'Bank transfer')
            ->where('invoices.data.0.payments.data.0.receivedOn', '2026-10-31'));
});

test('organization invoice history is paginated and never mixes organizations', function () {
    withBillingDetails();
    $tenants = twoTenants();
    inTenant($tenants->a->organization, function () {
        $subscription = Subscription::current();
        foreach (range(1, 51) as $sequence) {
            app(IssueSaasInvoice::class)->handle($subscription);
        }
    });

    $this->actingAs(platformStaff())->get(route('platform.organizations.show', $tenants->a->organization->id))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 50)->where('invoices.total', 51)
            ->where('invoices.data.0.number', 'SAAS-000051'));
    $this->get(route('platform.organizations.show', ['organization' => $tenants->a->organization->id, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)->where('invoices.current_page', 2)
            ->where('invoices.data.0.number', 'SAAS-000001'));
});

test('invoice payments and slips are independently paginated and balances include every payment', function () {
    withBillingDetails();
    $tenants = twoTenants();
    $invoice = inTenant($tenants->a->organization, function () {
        $invoice = app(IssueSaasInvoice::class)->handle(Subscription::current());
        foreach (range(1, 51) as $sequence) {
            SaasPayment::query()->create([
                'saas_invoice_id' => $invoice->id, 'amount' => 10, 'method' => 'bank_transfer',
                'reference' => 'PAY-'.$sequence, 'received_on' => '2026-10-31',
            ]);
            SaasPaymentSlip::query()->create([
                'saas_invoice_id' => $invoice->id, 'path' => 'payment-slips/private-'.$sequence,
                'original_name' => 'proof-'.$sequence.'.pdf',
            ]);
        }

        return $invoice;
    });
    $other = inTenant($tenants->b->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $paymentsPage = 'payments_'.$invoice->id;
    $slipsPage = 'slips_'.$invoice->id;

    $this->actingAs(platformStaff())->get(route('platform.organizations.show', $tenants->a->organization->id, absolute: false).'?'.$paymentsPage.'=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.total', 1)
            ->where('invoices.data.0.balance', 734490)
            ->has('invoices.data.0.payments.data', 10)
            ->where('invoices.data.0.payments.total', 51)
            ->where('invoices.data.0.payments.current_page', 2)
            ->has('invoices.data.0.slips.data', 10)
            ->where('invoices.data.0.slips.total', 51)
            ->where('invoices.data.0.slips.current_page', 1));
    $this->get(route('platform.invoices.show', $invoice->id).'?payments=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.balance', 734490)->has('payments.data', 1)->where('payments.total', 51)
            ->where('payments.current_page', 2)->has('slips.data', 50)->where('slips.current_page', 1));
    $this->get(route('platform.organizations.show', $tenants->a->organization->id).'?'.$slipsPage.'=6')
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data.0.slips.data', 1)
            ->where('invoices.data.0.slips.current_page', 6)->where('invoices.data.0.payments.current_page', 1));

    $this->actingAs($tenants->a->owner)->get(route('subscription.invoices.show', $invoice->id).'?slips=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.balance', 734490)->has('payments.data', 50)->has('slips.data', 1)
            ->where('slips.current_page', 2)->where('slips.total', 51)
            ->where('slips.data.0.url', fn ($url) => str_contains($url, '/subscription/slips/')));
    $this->get(route('subscription.invoices.show', $other->id).'?payments=2&slips=2')->assertNotFound();
});

test('setup and archived properties remain visible and incomplete VFMS credentials are not saved', function () {
    $tenants = twoTenants();
    $tenants->a->property->forceFill(['status' => 'setup', 'archived_at' => now()])->save();
    inTenant($tenants->a->property, fn () => VfmsCredential::query()->create([
        'integration_id' => 'partial-secret-id', 'mode' => 'sandbox',
    ]));

    $this->actingAs(platformStaff())->get(route('platform.organizations.show', $tenants->a->organization->id))
        ->assertDontSee('partial-secret-id')
        ->assertInertia(fn (Assert $page) => $page
            ->has('organization', fn (Assert $organization) => $organization->hasAll(['id', 'name', 'owners']))
            ->has('properties', 2)
            ->has('properties.1', fn (Assert $property) => $property->hasAll([
                'id', 'name', 'email', 'phone', 'status', 'statusLabel', 'archived', 'gates', 'vfmsMode', 'credentialsSaved', 'zraCheck', 'zraCheckedAt',
            ]))
            ->where('properties.1.status', 'setup')
            ->where('properties.1.archived', true)
            ->where('properties.1.credentialsSaved', false));
});

test('the organization page shows each property and its own gates without credentials or operational data', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    inTenant($property, function () use ($property, $tenants) {
        $property->forceFill(['status' => 'setup', 'go_live_requested_at' => now(), 'go_live_requested_by' => $tenants->a->owner->id])->save();
        $roomType = RoomType::query()->create(InventoryForm::roomType());
        Room::query()->create(InventoryForm::room($roomType->id));
        VfmsCredential::query()->create([
            'integration_id' => 'secret-integration-id', 'registration_id' => 'secret-registration-id',
            'token' => 'secret-vfms-token', 'mode' => 'live', 'zra_check' => 'accepted', 'zra_checked_at' => '2026-10-31 09:00:00',
        ]);
    });

    $response = $this->actingAs(platformStaff())->get(route('platform.organizations.show', $tenants->a->organization->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties', 2)
            ->where('properties.0.name', 'Alpha Arusha Lodge')
            ->where('properties.0.status', 'live')
            ->where('properties.0.vfmsMode', 'sandbox')
            ->where('properties.0.credentialsSaved', false)
            ->where('properties.0.zraCheck', null)
            ->where('properties.0.zraCheckedAt', null)
            ->where('properties.0.gates.0.passed', false)
            ->where('properties.1.name', 'Alpha Stone Town Inn')
            ->where('properties.1.status', 'go_live_requested')
            ->where('properties.1.email', 'stonetown@alpha.test')
            ->where('properties.1.vfmsMode', 'live')
            ->where('properties.1.credentialsSaved', true)
            ->where('properties.1.zraCheck', 'accepted')
            ->where('properties.1.zraCheckedAt', '2026-10-31T09:00:00+00:00')
            ->where('properties.1.gates.0', ['key' => 'room_type', 'label' => 'At least one room type', 'passed' => true])
            ->where('properties.1.gates.1.passed', true)
            ->where('properties.1.gates.2.passed', false)
            ->where('properties.1.gates.8', ['key' => 'vfms', 'label' => 'VFMS credentials accepted by ZRA and set to Live', 'passed' => true])
            ->missing('guests')->missing('reservations')->missing('folios'));

    foreach (['secret-integration-id', 'secret-registration-id', 'secret-vfms-token', 'Bravo'] as $secret) {
        $response->assertDontSee($secret);
    }
});
