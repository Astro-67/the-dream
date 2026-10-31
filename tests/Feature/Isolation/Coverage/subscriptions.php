<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Models\BillingDetails;
use App\Domains\Subscriptions\Models\Plan;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\SaasPaymentSlip;
use App\Domains\Subscriptions\Models\Subscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web/subscriptions.php. See README.md.
 *
 * The subscription page reads only the organization in context: Alpha's owner
 * sees Alpha's usage, never Bravo's. Plans are platform pages, refused to every
 * organization member, owners included, and so are SaaS invoices and payments:
 * an owner sees only their own invoices on the subscription page.
 */
$planPayload = fn (array $overrides = []): array => [
    'name' => 'Hijacked', 'code' => 'HIJACK', 'property_limit' => 99, 'trial_days' => 90,
    'grace_days' => 30, 'billing_period' => 'yearly', 'price' => 0, 'currency' => 'USD', 'is_default' => true, ...$overrides,
];

$bravoSlip = function (TwoTenants $t): SaasPaymentSlip {
    withBillingDetails();

    return inTenant($t->b->organization, function () {
        $invoice = app(IssueSaasInvoice::class)->handle(Subscription::current());

        return $invoice->slips()->create(['path' => 'private/bravo.png', 'original_name' => 'Bravo payment.png', 'reference' => 'BRAVO-TRANSFER']);
    });
};

return [
    'subscription.invoices.slips.store' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoSlip) {
        Storage::fake('local');
        $slip = $bravoSlip($t);
        $before = inTenant($t->b->organization, fn () => SaasInvoice::query()->findOrFail($slip->saas_invoice_id)->toArray());
        $responses = array_values(array_map(fn ($member) => $this->actingAs($member)
            ->post(route('subscription.invoices.slips.store', $slip->saas_invoice_id), [
                'files' => [UploadedFile::fake()->image('hijack.png')], 'reference' => 'HIJACK',
            ])->assertNotFound(), $t->a->members()));

        expect(inTenant($t->b->organization, fn () => SaasInvoice::query()->findOrFail($slip->saas_invoice_id)->toArray()))->toEqual($before)
            ->and(inTenant($t->b->organization, fn () => SaasPaymentSlip::query()->count()))->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toBe([]);

        return $responses;
    }),

    'subscription.slips.show' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoSlip) {
        $slip = $bravoSlip($t);

        return array_values(array_map(fn ($member) => $this->actingAs($member)
            ->get(route('subscription.slips.show', $slip->id))->assertNotFound(), $t->a->members()));
    }),

    'platform.slips.show' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoSlip) {
        $slip = $bravoSlip($t);

        return array_values(array_map(fn ($member) => $this->actingAs($member)
            ->get(route('platform.slips.show', $slip->id))->assertForbidden(), $t->a->members()));
    }),

    'subscription.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        inTenant($t->b->property, function () {
            $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
            app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
        });
        withBillingDetails();
        $bravoInvoice = inTenant($t->b->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
        $responses = array_values(array_map(fn ($member) => $this->actingAs($member)->get(route('subscription.show')), $t->a->members()));

        $this->actingAs($t->a->owner)->get(route('subscription.show'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('usage', ['properties' => 2, 'rooms' => 0])->has('invoices.data', 0))
            ->assertDontSee($bravoInvoice->id);

        return $responses;
    }),

    'subscription.rooms.update' => RouteCoverage::attempt(function (TwoTenants $t) {
        // The route carries no ID: it can only change the caller's own organization.
        $responses = array_values(array_map(fn ($member) => $this->actingAs($member)->put(route('subscription.rooms.update'), ['rooms' => 7]), $t->a->members()));

        expect(inTenant($t->b->organization, fn () => Subscription::current()->rooms))->toBe(150);

        return $responses;
    }),

    'platform.organizations.rooms.update' => RouteCoverage::attempt(function (TwoTenants $t) {
        $responses = array_values(array_map(fn ($member) => $this->actingAs($member)
            ->put(route('platform.organizations.rooms.update', $t->b->organization->id), ['rooms' => 7])->assertForbidden(), $t->a->members()));

        expect(inTenant($t->b->organization, fn () => Subscription::current()->rooms))->toBe(150);

        return $responses;
    }),

    'platform.plans.index' => RouteCoverage::attempt(fn (TwoTenants $t) => array_values(array_map(
        fn ($member) => $this->actingAs($member)->get(route('platform.plans.index'))->assertForbidden(),
        $t->a->members(),
    ))),

    'platform.plans.store' => RouteCoverage::attempt(function (TwoTenants $t) use ($planPayload) {
        $response = $this->actingAs($t->a->owner)->post(route('platform.plans.store'), $planPayload())->assertForbidden();

        expect(Plan::query()->where('code', 'HIJACK')->exists())->toBeFalse();

        return $response;
    }),

    'platform.plans.update' => RouteCoverage::attempt(function (TwoTenants $t) use ($planPayload) {
        $plan = Plan::default();
        $plan->forceFill(['modules' => ['reservations' => true, 'billing' => false]])->save();
        $before = $plan->toArray();

        $response = $this->actingAs($t->a->owner)->put(route('platform.plans.update', $plan), $planPayload())->assertForbidden();

        expect($plan->fresh()?->toArray())->toEqual($before);

        return $response;
    }),

    'platform.invoices.index' => RouteCoverage::attempt(fn (TwoTenants $t) => array_values(array_map(
        fn ($member) => $this->actingAs($member)->get(route('platform.invoices.index'))->assertForbidden(),
        $t->a->members(),
    ))),

    'platform.invoices.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $response = $this->actingAs($t->a->owner)->post(route('platform.invoices.store'), ['organization_id' => $t->b->organization->id])->assertForbidden();

        expect(inTenant($t->b->organization, fn () => SaasInvoice::query()->count()))->toBe(0);

        return $response;
    }),

    'platform.invoices.payments.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        withBillingDetails();
        $invoice = inTenant($t->b->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));

        $responses = [
            $this->actingAs($t->a->owner)->post(route('platform.invoices.payments.store', $invoice->id), [
                'amount' => $invoice->amount, 'method' => 'bank_transfer', 'reference' => 'HIJACK', 'received_on' => now()->toDateString(),
            ])->assertForbidden(),
        ];

        expect($invoice->fresh()?->status)->toBe(SaasInvoiceStatus::Open)
            ->and(inTenant($t->b->organization, fn () => SaasPayment::query()->count()))->toBe(0);

        return $responses;
    }),

    'platform.billing-details.edit' => RouteCoverage::attempt(fn (TwoTenants $t) => array_values(array_map(
        fn ($member) => $this->actingAs($member)->get(route('platform.billing-details.edit'))->assertForbidden(),
        $t->a->members(),
    ))),

    'platform.billing-details.update' => RouteCoverage::attempt(function (TwoTenants $t) {
        $before = withBillingDetails()->toArray();

        $response = $this->actingAs($t->a->owner)->put(route('platform.billing-details.update'), [
            'business_name' => 'Hijacked', 'address' => 'x', 'phone' => 'x', 'email' => 'x@example.test', 'tin' => 'x',
            'accounts' => [['type' => 'mobile_money', 'provider' => 'x', 'account_name' => 'Hijacked', 'account_number' => '1']],
        ])->assertForbidden();

        expect(BillingDetails::current()?->toArray())->toEqual($before);

        return $response;
    }),

    'subscription.invoices.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        withBillingDetails();
        $invoice = inTenant($t->b->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));

        return array_values(array_map(
            fn ($member) => $this->actingAs($member)->get(route('subscription.invoices.show', $invoice->id))->assertNotFound(),
            $t->a->members(),
        ));
    }),

    'platform.invoices.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        withBillingDetails();
        $invoice = inTenant($t->b->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));

        return array_values(array_map(
            fn ($member) => $this->actingAs($member)->get(route('platform.invoices.show', $invoice->id))->assertForbidden(),
            $t->a->members(),
        ));
    }),
];
