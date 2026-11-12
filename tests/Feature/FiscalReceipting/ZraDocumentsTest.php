<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    config(['services.vfms.test_viewer_url' => 'https://vfms-viewer.example']);
});

/** The ZRA documents page of $reservation, as $role in the tenant's property. */
function zraDocumentsPage($test, TenantFixture $tenant, $reservation, OrganizationRole $role = OrganizationRole::FrontDesk)
{
    return $test->actingAs($role === OrganizationRole::Owner ? $tenant->owner : $tenant->member($role))
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $reservation));
}

// protects=one page shows a group's general receipts and what is still held, from any room (spec #249 UI §1); fails_when=a section or count is missing or the other room shows nothing; no_claim=print layout.
test('a group\'s ZRA documents list its general receipt and the extras still held, from any room', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER', 'quantity' => 2, 'unit_price' => 1800]);
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'LATE BAR', 'quantity' => 1, 'unit_price' => 900]);

    zraDocumentsPage($this, $t, $second)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('fiscal-receipting/checkout-report')
        ->where('rooms', 2)
        ->where('payingInstitution', ['name' => 'Ministry of Health', 'zrbNumber' => 'WA2400117'])
        ->where('sandbox', true)
        ->has('generalReceipts', 1)
        ->where('generalReceipts.0.number', 'TEST-RECEIPT-169')
        ->where('generalReceipts.0.customer', 'Ministry of Health')
        ->where('generalReceipts.0.items', 1)
        ->where('generalReceipts.0.amount', 3600)
        ->where('generalReceipts.0.currency', 'USD')
        ->where('generalReceipts.0.status', 'issued')
        ->where('generalReceipts.0.portalUrl', 'https://vfms-viewer.example/receipt-form/TEST-RECEIPT-169')
        ->where('generalReceipts.0.printUrl', fn (string $url) => str_starts_with($url, route('reservations.fiscal-report.print', $second).'?document='))
        ->has('held', 1)
        ->where('held.0.description', 'LATE BAR')
        ->where('held.0.room', '101')
        ->where('held.0.amount', 900)
        ->where('serviceReceipts', [])
        ->where('guestReports', [])
        ->has('summary', 1)->where('summary.0.currency', 'USD')->where('summary.0.held', 900));
});
