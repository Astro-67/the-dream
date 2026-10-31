<?php

use App\Domains\Subscriptions\Actions\AdvanceSubscription;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use Spatie\Activitylog\Models\Activity;

test('issuing an invoice by hand is refused and audited until billing details and an account exist', function () {
    [, $organization] = organizationWithOwner();

    $this->actingAs(platformStaff())->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertSessionHasErrors('organization_id');

    expect(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(0)
        ->and(Activity::query()->where('event', 'refused')->where('properties->result', 'refused')->exists())->toBeTrue();
});

test('the hourly renewal skips issuing, with an audited reason, while no payment account exists, and still advances', function () {
    withBillingDetails(['accounts' => []]);
    [, $organization] = organizationWithOwner();

    inTenant($organization, function () {
        Subscription::current()->forceFill(['trial_ends_at' => now()->addDay()])->save();
        app(AdvanceSubscription::class)->handle();

        expect(SaasInvoice::query()->count())->toBe(0)
            ->and(Subscription::current()->last_advanced_at)->not->toBeNull();
    });
    expect(Activity::query()->where('event', 'refused')->where('description', 'SaaS invoice refused')->sole()->properties['errors'])
        ->toHaveKey('organization_id');
});
