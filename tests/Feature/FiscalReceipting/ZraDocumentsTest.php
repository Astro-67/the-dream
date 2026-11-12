<?php

use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\Actions\IssueGeneralReceipt;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DrainOutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
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

test('the group statement shows accepted ZRA documents and held totals without claiming a refused receipt', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['unit_price' => 3600]);
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();
    postGeneralReceiptExtra($this, $t, $folio, ['unit_price' => 900]);
    fakeGeneralReceiptService(Http::response(['statusCode' => 5000, 'message' => 'Refused']));
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();
    $tzs = inTenant($t->property, fn () => app(OpenFolio::class)->handle($t->owner, $second, 'TZS'));
    postGeneralReceiptExtra($this, $t, $tzs, ['unit_price' => 75000]);

    $this->withSession(['tenancy.organization_id' => $t->organization->id, 'tenancy.property_id' => $t->property->id])
        ->get(route('reservations.group-statement', $second))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('zraDocuments.generalReceipts', 1)
        ->where('zraDocuments.generalReceipts.0.number', 'TEST-RECEIPT-169')
        ->where('zraDocuments.generalReceipts.0.status', 'issued')
        ->where('zraDocuments.generalReceipts.0.amount', 3600)
        ->where('zraDocuments.guestReports', [])
        ->where('zraDocuments.held', [['currency' => 'TZS', 'amount' => 75000], ['currency' => 'USD', 'amount' => 900]])
        ->where('zraDocuments.url', route('reservations.fiscal-report', $second)));
});

test('a reservation ZRA card counts the whole group and flags refused filings', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['unit_price' => 3600]);
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();
    postGeneralReceiptExtra($this, $t, $folio, ['unit_price' => 900]);
    fakeGeneralReceiptService(Http::response(['statusCode' => 5000, 'message' => 'Refused']));
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();

    $this->withSession(['tenancy.organization_id' => $t->organization->id, 'tenancy.property_id' => $t->property->id])
        ->get(route('reservations.show', $second))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('zraDocuments.counts', ['generalReceipts' => 2, 'serviceReceipts' => 0, 'guestReports' => 0, 'held' => 1])
        ->where('zraDocuments.held', [['currency' => 'USD', 'amount' => 900]])
        ->where('zraDocuments.needsAttention', true)
        ->where('zraDocuments.url', route('reservations.fiscal-report', $second))
        ->where('zraDocuments.statementUrl', route('reservations.group-statement', $second))
        ->has('vfmsFilings', 1));
});

test('ZRA summaries exclude same-name institutions in other properties even with a shared group identifier', function () {
    $tenants = twoTenants();
    $tenant = $tenants->a;
    [$first, $second] = generalReceiptGroup($tenant);
    $folio = inTenant($tenant->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $tenant, $folio, ['unit_price' => 900]);
    foreach ([[$tenant, $tenant->otherProperty], [$tenants->b, $tenants->b->property]] as [$foreign, $property]) {
        fakeGeneralReceiptService(Http::response(['receiptNumber' => 'FOREIGN-'.$property->id]));
        inTenant($property, function () use ($foreign, $property, $first) {
            $property->update(['jurisdiction_profile' => 'zanzibar']);
            $stay = app(CreateReservation::class)->handle($foreign->owner, [
                ...BookingForm::details($foreign->owner),
                'paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health',
                'paying_institution_zrb_number' => 'WA2400117',
            ]);
            $stay->update(['booking_group_id' => $first->booking_group_id]);
            app(PostExtraCharge::class)->handle($foreign->owner, Folio::query()->where('reservation_id', $stay->id)->sole(), [
                'category' => 'restaurant', 'description' => 'FOREIGN DINNER', 'quantity' => 1, 'unit_price' => 77700,
            ]);
            app(IssueGeneralReceipt::class)->forReservation($foreign->owner, $stay);
        });
        expect(inTenant($property, fn () => IssuedDocument::query()->where('number', 'FOREIGN-'.$property->id)->exists()))->toBeTrue();
    }
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id,
    ]);
    foreach (['reservations.show', 'reservations.group-statement'] as $route) {
        $this->get(route($route, $second))->assertOk()->assertDontSee('FOREIGN')->assertInertia(fn (Assert $page) => $page
            ->where('zraDocuments.counts', ['generalReceipts' => 0, 'serviceReceipts' => 0, 'guestReports' => 0, 'held' => 1])
            ->where('zraDocuments.held', [['currency' => 'USD', 'amount' => 900]])
            ->where('zraDocuments.generalReceipts', []));
    }
    $this->get(route('receipts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.total', 0)->where('tabCounts', ['zra_receipts' => 0, 'checkout_reports' => 0, 'non_fiscal' => 0]));
});

test('reservation and statement hide ZRA sections for Mainland and demo properties', function (string $kind) {
    $tenant = twoTenants()->a;
    $stay = generalReceiptStay($tenant, checkIn: false);
    inTenant($tenant->property, fn () => DB::table('properties')->where('id', $tenant->property->id)
        ->update($kind === 'demo' ? ['demo' => true] : ['jurisdiction_profile' => 'mainland_tanzania']));
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id,
    ]);
    foreach (['reservations.show', 'reservations.group-statement'] as $route) {
        $this->get(route($route, $stay))->assertOk()->assertInertia(fn (Assert $page) => $page->where('zraDocuments', null));
    }
})->with(['mainland', 'demo']);

test('the group statement lists each accepted check-out report against its room', function () {
    $tenant = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($tenant);
    foreach (['2026-12-10', '2026-12-11'] as $date) {
        inTenant($tenant->property, fn () => app(CloseBusinessDate::class)->handle($tenant->owner, $tenant->property->fresh(), $date));
    }
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id,
    ])->post(route('reservations.check-out', $first), ['_property' => $tenant->property->id, 'override_reason' => 'Ministry pays later'])->assertSessionHasNoErrors();
    $this->get(route('reservations.group-statement', $second))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('zraDocuments.guestReports', [['number' => 'TEST-CHECKOUT-169', 'room' => '101']])
        ->where('zraDocuments.counts.guestReports', 1));
});

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

/** ZRA's service/ answer to a WS, in the shape the test server returned on 12 Nov 2026 (receipt XHE2104835). */
function zraDocumentsWsAnswer(): array
{
    return ['documentNumber' => 'Ministry of Health', 'service' => [['itemName' => 'DINNER', 'discount' => 6.0, 'price' => 18.0, 'quantity' => 2.0], ['itemName' => 'WATER', 'discount' => 0.0, 'price' => 5.0, 'quantity' => 3.0]],
        'businessUnitId' => 5940, 'recordedAt' => '2026-12-10T12:40:19.586+03:00', 'tax' => 5.869565217391304, 'taxExclusive' => 39.130434782608695, 'salesAmount' => 45.0,
        'receiptNumber' => 'XHE2104835', 'type' => 'WS', 'currency' => 'USD'];
}

test('receipt reprints use only stored business details even after property settings change', function (bool $general, bool $stored) {
    $t = twoTenants()->a;
    $business = $stored ? ['businessName' => 'Issued business', 'street' => 'Issued street', 'phoneNumber' => '+255777123456', 'tinNumber' => 'TIN-ISSUED', 'vrnNumber' => 'VRN-ISSUED', 'znumber' => 'Z-ISSUED'] : [];
    fakeGeneralReceiptService(Http::response([...zraDocumentsWsAnswer(), ...$business]));
    $stay = generalReceiptStay($t, $general ? ['paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health'] : []);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    postGeneralReceiptExtra($this, $t, $folio, []);
    if ($general) {
        issueGeneralReceiptAs($this, $t, $stay)->assertSessionHasNoErrors();
    }
    inTenant($t->property, fn () => $t->property->update(['name' => 'Current business', 'address_line' => 'Current street', 'phone' => 'Current phone', 'tin' => 'Current TIN', 'vrn' => 'Current VRN', 'z_number' => 'Current Z-number']));

    $this->actingAs($t->owner)->withSession(['tenancy.organization_id' => $t->organization->id, 'tenancy.property_id' => $t->property->id])
        ->get(route('reservations.fiscal-report.print', $stay))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('documents', 1)->where('documents.0.business', [
            'name' => $stored ? 'Issued business' : null, 'street' => $stored ? 'Issued street' : null,
            'phone' => $stored ? '+255777123456' : null, 'tin' => $stored ? 'TIN-ISSUED' : null,
            'vrn' => $stored ? 'VRN-ISSUED' : null, 'znumber' => $stored ? 'Z-ISSUED' : null,
        ]));
})->with([false, true])->with([false, true]);

// protects=a printed ZRA document shows ZRA's own figures, never Karafuu's (spec #249 UI §2); fails_when=figures come from the folio, the customer or business is missing, or a guest document is printed; no_claim=physical paper layout (browser check).
test('a printed general receipt and check-out report show ZRA\'s stored figures, and Print all prints every document', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    // Karafuu's own line differs from ZRA's answer on purpose: the print must show ZRA's.
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER', 'quantity' => 2, 'unit_price' => 1800, 'discount' => 600]);
    fakeGeneralReceiptService(Http::response(zraDocumentsWsAnswer()));
    foreach (['2026-12-10', '2026-12-11'] as $date) {
        inTenant($t->property, fn () => app(CloseBusinessDate::class)->handle($t->owner, $t->property->fresh(), $date));
    }
    foreach ([$first, $second] as $room) {
        $this->post(route('reservations.check-out', $room), ['_property' => $t->property->id, 'override_reason' => 'Ministry pays later'])->assertSessionHasNoErrors();
    }
    $receipt = inTenant($t->property, fn () => IssuedDocument::query()->where('type', 'general_receipt')->sole());
    $report = inTenant($t->property, fn () => IssuedDocument::query()->where('type', 'checkout_report')->where('number', 'TEST-CHECKOUT-169')->sole());
    $print = fn (array $query, ?Reservation $stay = null) => $this->actingAs($t->member(OrganizationRole::FrontDesk))
        ->withSession(['tenancy.organization_id' => $t->organization->id, 'tenancy.property_id' => $t->property->id])
        ->get(route('reservations.fiscal-report.print', [$stay ?? $second, ...$query]));

    $print(['document' => $receipt->id])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('fiscal-receipting/zra-document')
        ->where('sandbox', true)
        ->has('documents', 1)
        ->where('documents.0.type', 'general_receipt')
        ->where('documents.0.number', 'XHE2104835')
        ->where('documents.0.customer', 'Ministry of Health')
        ->where('documents.0.currency', 'USD')
        ->where('documents.0.issuedAt', '2026-12-10T09:40:19+00:00')
        ->where('documents.0.items', [
            ['name' => 'DINNER', 'quantity' => 2, 'price' => 1800, 'discount' => 600, 'amount' => 3000],
            ['name' => 'WATER', 'quantity' => 3, 'price' => 500, 'discount' => 0, 'amount' => 1500],
        ])
        ->where('documents.0.taxExclusive', 3913)->where('documents.0.tax', 587)->where('documents.0.total', 4500)
        ->where('documents.0.qrSvg', fn (string $svg) => str_starts_with($svg, '<svg')));
    $print(['document' => $report->id])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.0.type', 'checkout_report')
        ->where('documents.0.customer', 'Anna Berg')
        ->where('documents.0.business.name', null)
        ->where('documents.0.rooms', [['room' => '101', 'days' => 2, 'price' => 11000, 'amount' => 22000, 'tax' => 0]])
        ->where('documents.0.items', [])
        ->where('documents.0.total', 22000)->where('documents.0.tax', 0)->where('documents.0.levy', 0))
        ->assertDontSee('P1234567');
    $print([])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents', fn ($documents) => collect($documents)->pluck('number')->sort()->values()->all() === ['TEST-CHECKOUT-169', 'TEST-CHECKOUT-169-2', 'XHE2104835']))
        ->assertDontSee('P1234567')->assertDontSee('P7654321');

    // A document of a stay outside this booking group is not this page's.
    $print(['document' => $receipt->id], $first)->assertOk();
    inTenant($t->property, fn () => $first->forceFill(['booking_group_id' => null])->save());
    $print(['document' => $receipt->id], $first)->assertNotFound();
});

// protects=every state shows its sections and counts, and a refused receipt shows ZRA's reason with Re-file (spec #249 UI §1); fails_when=a state hides a section, loses a count, or a refusal has no reason or action; no_claim=Live acceptance.
test('waiting, refused and complete states keep one layout with their counts and recovery actions', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    $folio = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'DINNER']);
    fakeGeneralReceiptService(Http::response(['statusCode' => 5000, 'statusMessage' => 'Invalid item']));
    issueGeneralReceiptAs($this, $t, $first)->assertSessionHasNoErrors();
    [$message] = generalReceiptMessages($t, 'generalReceipt');

    // Refused: the receipt returns to "not yet receipted" and offers Re-file, to a role that may recover filings.
    zraDocumentsPage($this, $t, $first, OrganizationRole::Owner)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status', 'waiting')
        ->has('generalReceipts', 1)
        ->where('generalReceipts.0.number', null)
        ->where('generalReceipts.0.status', 'attention')
        ->where('generalReceipts.0.reason', fn (string $reason) => str_contains($reason, 'ZRA refused'))
        ->where('generalReceipts.0.refileUrl', route('reservations.vfms.refile', [$first, $message]))
        ->where('generalReceipts.0.printUrl', null)
        ->has('held', 1)->where('held.0.description', 'DINNER'));
    // The same page for a role that cannot recover filings carries no Re-file.
    zraDocumentsPage($this, $t, $first, OrganizationRole::Finance)->assertInertia(fn (Assert $page) => $page
        ->where('generalReceipts.0.status', 'attention')->where('generalReceipts.0.refileUrl', null));

    // Waiting for ZRA to answer: sending, with nothing to print yet.
    inTenant($t->property, fn () => $message->forceFill(['status' => 'pending'])->save());
    zraDocumentsPage($this, $t, $first, OrganizationRole::Owner)->assertInertia(fn (Assert $page) => $page
        ->where('generalReceipts.0.status', 'sending')->where('generalReceipts.0.reason', null)->where('generalReceipts.0.printUrl', null));
});

// protects=no guest identity document reaches a page or print without permission (spec #249); fails_when=a passport number is shown to a role without GuestIdentityView or before an audited reveal.
test('the ZRA documents page and print never carry a guest document before an audited reveal', function () {
    $t = twoTenants()->a;
    [$first, $second] = generalReceiptGroup($t);
    foreach (['2026-12-10', '2026-12-11'] as $date) {
        inTenant($t->property, fn () => app(CloseBusinessDate::class)->handle($t->owner, $t->property->fresh(), $date));
    }
    foreach ([$first, $second] as $room) {
        $this->actingAs($t->owner)->post(route('reservations.check-out', $room), ['_property' => $t->property->id, 'override_reason' => 'Ministry pays later'])->assertSessionHasNoErrors();
    }

    foreach ([OrganizationRole::Finance, OrganizationRole::FrontDesk, OrganizationRole::Owner] as $role) {
        zraDocumentsPage($this, $t, $second, $role)->assertOk()->assertDontSee('P1234567')->assertDontSee('P7654321');
        $this->get(route('reservations.fiscal-report.print', $second))->assertOk()->assertDontSee('P1234567')->assertDontSee('P7654321');
    }
    zraDocumentsPage($this, $t, $second, OrganizationRole::Finance)->assertInertia(fn (Assert $page) => $page
        ->has('guestReports', 2)->where('guestReports.0.identity', null)->where('revealIdentityUrl', null));
});

// protects=account pages reuse the ZRA sections and print their own stored receipt, never another account's.
test('a customer account reuses the ZRA sections and can print its own general receipt only', function () {
    $t = twoTenants()->a;
    $account = inTenant($t->property, fn () => app(OpenCustomerAccount::class)->handle($t->owner, ['kind' => 'day_use', 'customer_name' => 'Pool visitor', 'currency' => 'USD']));
    $folio = inTenant($t->property, fn () => Folio::query()->where('customer_account_id', $account->id)->sole());
    postGeneralReceiptExtra($this, $t, $folio, ['description' => 'POOL', 'unit_price' => 4500]);
    fakeGeneralReceiptService(Http::response([...zraDocumentsWsAnswer(), 'documentNumber' => 'Pool visitor']));
    inTenant($t->property, function () use ($t, $folio) {
        app(RecordPayment::class)->handle($t->owner, $folio, ['method' => 'bank_transfer', 'amount' => 4500, 'currency' => 'USD']);
    });
    $this->actingAs($t->owner)->withSession(['tenancy.organization_id' => $t->organization->id, 'tenancy.property_id' => $t->property->id]);
    $this->get(route('customer-accounts.show', $account))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('zraDocuments.held', 1)->where('zraDocuments.generalReceipts', []));
    $this->post(route('customer-accounts.close', $account), ['_property' => $t->property->id])->assertSessionHasNoErrors();
    $this->get(route('customer-accounts.show', $account))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('zraDocuments.held', [])->has('zraDocuments.generalReceipts', 1)
        ->where('zraDocuments.generalReceipts.0.customer', 'Pool visitor')
        ->where('zraDocuments.generalReceipts.0.status', 'issued'));
    $this->get(route('customer-accounts.print', $account))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('fiscal-receipting/zra-document')->has('documents', 1)->where('documents.0.total', 4500));
});

// protects=historical service answers can omit fields; a missing guest name never exposes documentNumber in print.
test('a historical guest receipt with missing fields prints no invented figures or document identity', function () {
    $t = twoTenants()->a;
    Queue::fake([DrainOutboxMessage::class]);
    [$stay, $old] = correctionDocuments($t);
    $this->actingAs($t->owner)->withSession(['tenancy.organization_id' => $t->organization->id, 'tenancy.property_id' => $t->property->id])
        ->get(route('reservations.fiscal-report.print', [$stay, 'document' => $old->id]))->assertOk()
        ->assertDontSee('PRIVATE-GUEST-ID')->assertInertia(fn (Assert $page) => $page
        ->where('documents.0.total', null)->where('documents.0.customer', 'Travel Booker'));
});
