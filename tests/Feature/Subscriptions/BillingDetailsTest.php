<?php

use App\Domains\Subscriptions\Models\SaasInvoice;
use Spatie\Activitylog\Models\Activity;

test('issuing an invoice by hand is refused and audited until billing details and an account exist', function () {
    [, $organization] = organizationWithOwner();

    $this->actingAs(platformStaff())->post(route('platform.invoices.store'), ['organization_id' => $organization->id])
        ->assertSessionHasErrors('organization_id');

    expect(inTenant($organization, fn () => SaasInvoice::query()->count()))->toBe(0)
        ->and(Activity::query()->where('event', 'refused')->where('properties->result', 'refused')->exists())->toBeTrue();
});
