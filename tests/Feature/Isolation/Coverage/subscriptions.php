<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Models\Plan;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\Subscription;
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
    'name' => 'Hijacked', 'code' => 'HIJACK', 'property_limit' => 99, 'room_limit' => 999, 'trial_days' => 90,
    'grace_days' => 30, 'billing_period' => 'yearly', 'price' => 0, 'currency' => 'USD', 'is_default' => true, ...$overrides,
];

return [
    'subscription.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        inTenant($t->b->property, function () {
            $type = app(CreateRoomType::class)->handle(null, InventoryForm::roomType());
            app(CreateRoom::class)->handle(null, InventoryForm::room($type->id));
        });
        $bravoInvoice = inTenant($t->b->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
        $responses = array_values(array_map(fn ($member) => $this->actingAs($member)->get(route('subscription.show')), $t->a->members()));

        $this->actingAs($t->a->owner)->get(route('subscription.show'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('usage', ['properties' => 2, 'rooms' => 0])->has('invoices.data', 0))
            ->assertDontSee($bravoInvoice->id);

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
        $before = $plan->toArray();

        $response = $this->actingAs($t->a->owner)->put(route('platform.plans.update', $plan), $planPayload())->assertForbidden();

        expect($plan->fresh()?->toArray())->toBe($before);

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
];
