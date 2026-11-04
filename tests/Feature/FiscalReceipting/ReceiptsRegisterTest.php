<?php

use Inertia\Testing\AssertableInertia as Assert;

test('receipts register has exactly the payments access gate and defaults to all dates', function () {
    $tenant = twoTenants()->a;
    foreach ($tenant->members() as $member) {
        $this->actingAs($member)->withSession([
            'tenancy.organization_id' => $tenant->organization->id,
            'tenancy.property_id' => $tenant->property->id,
        ]);
        $payments = $this->get(route('payments.index'));
        $receipts = $this->get(route('receipts.index'));
        expect($receipts->status())->toBe($payments->status());
        if ($receipts->isOk()) {
            $receipts->assertInertia(fn (Assert $page) => $page
                ->component('receipts/index')
                ->where('filters', [])
                ->where('documents.data', [])
                ->where('documents.total', 0));
        }
    }
});
