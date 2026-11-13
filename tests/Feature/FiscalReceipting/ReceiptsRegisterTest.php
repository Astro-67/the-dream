<?php

use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\GuestLeaves;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

function receiptsRegisterStay(TenantFixture $tenant): array
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [
            ...$details, 'adults' => 2, 'children' => 0, 'nightly_rate' => 11000, 'room_id' => Room::query()->sole()->id,
        ]);
        foreach ([['Anna Berg', 'P1234567'], ['Ola Berg', 'P7654321']] as [$name, $number]) {
            $guest = app(CreateGuest::class)->handle($tenant->owner, [
                'name' => $name, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => 'DE']],
            ]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        }
        app(CheckIn::class)->handle($tenant->owner, $reservation);
        $folio = Folio::query()->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['category' => 'bar', 'description' => 'Water', 'quantity' => 2, 'unit_price' => 1000]);
        app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'bank_transfer', 'amount' => 2000, 'currency' => 'USD']);

        return [$reservation, Receipt::query()->sole()];
    });
}

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
                ->where('filters', ['tab' => 'zra_receipts'])
                ->where('documents.data', [])
                ->where('documents.total', 0));
        }
    }
});

test('receipt tabs separate guest and general receipts from reports and non-fiscal payments with scoped counts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    $tenant = twoTenants()->a;
    [$stay] = receiptsRegisterStay($tenant);
    inTenant($tenant->property, fn () => app(GuestLeaves::class)->handle($tenant->owner, $stay, ReservationGuest::query()->orderByDesc('id')->firstOrFail()->guest_id));
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id,
        'tenancy.property_id' => $tenant->property->id,
    ]);

    $this->get(route('receipts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.tab', 'zra_receipts')
        ->where('tabCounts', ['zra_receipts' => 1, 'checkout_reports' => 1, 'non_fiscal' => 1])
        ->has('documents.data', 1)->where('documents.data.0.type', 'services_receipt'));
    foreach (['checkout_reports' => 'checkout_report', 'non_fiscal' => 'non_fiscal'] as $tab => $type) {
        $this->get(route('receipts.index', ['tab' => $tab]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('documents.data', 1)->where('documents.data.0.type', $type));
    }
    $this->getJson(route('receipts.index', ['tab' => 'all']))->assertUnprocessable()->assertJsonValidationErrors('tab');
});

test('receipt sources link only to authorized stays groups and customer accounts', function (string $kind, string $label) {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    $tenant = twoTenants()->a;
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id,
    ]);
    if (in_array($kind, ['day_use', 'event'], true)) {
        $source = inTenant($tenant->property, fn () => app(OpenCustomerAccount::class)->handle($tenant->owner, [
            'kind' => $kind, 'customer_name' => 'Ministry of Health', 'currency' => 'USD',
            'items' => [['category' => 'other', 'description' => 'LUNCH', 'quantity' => 1, 'unit_price' => 900]],
        ]));
        inTenant($tenant->property, fn () => app(RecordPayment::class)->handle($tenant->owner, $source->folios()->sole(), ['method' => 'bank_transfer', 'amount' => 900, 'currency' => 'USD']));
        $this->post(route('customer-accounts.close', $source), ['_property' => $tenant->property->id])->assertSessionHasNoErrors();
        $url = route('customer-accounts.show', $source);
        $number = $source->number;
    } elseif ($kind === 'group') {
        [$source] = generalReceiptGroup($tenant);
        $folio = inTenant($tenant->property, fn () => Folio::query()->where('reservation_id', $source->id)->sole());
        postGeneralReceiptExtra($this, $tenant, $folio, ['unit_price' => 900]);
        issueGeneralReceiptAs($this, $tenant, $source)->assertSessionHasNoErrors();
        $url = route('reservations.group-statement', $source);
        $number = $source->reservation_number;
    } else {
        [$source] = receiptsRegisterStay($tenant);
        $url = route('reservations.show', $source);
        $number = $source->reservation_number;
    }
    $this->get(route('receipts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('documents.data', 1)
        ->where('documents.data.0.source', ['label' => $label, 'number' => $number, 'url' => $url]));
    $this->get($url)->assertOk();
    $reader = $tenant->member(OrganizationRole::Auditor);
    inTenant($tenant->property, function () use ($reader) {
        $reader->syncRoles([]);
        $reader->givePermissionTo(OrganizationPermission::ReportsView->value);
    });
    $this->actingAs($reader)->get(route('receipts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.data.0.source.url', null)
        ->where('documents.data.0.localUrl', fn ($url) => $source instanceof CustomerAccount ? $url === null : str_contains($url, '/fiscal-report')));
    $this->get($url)->assertForbidden();
})->with([['stay', 'Stay'], ['group', 'Group'], ['day_use', 'Day use'], ['event', 'Event']]);

test('register links issued documents to their local pages and stored viewers without identity data', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    config(['services.vfms.test_viewer_url' => 'https://vfms-viewer.example']);
    $tenant = twoTenants()->a;
    [$stay, $receipt] = receiptsRegisterStay($tenant);
    $response = $this->actingAs($tenant->member(OrganizationRole::Auditor))->withSession([
        'tenancy.organization_id' => $tenant->organization->id,
        'tenancy.property_id' => $tenant->property->id,
    ])->get(route('receipts.index'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('documents.total', 1)
        ->where('documents.data', function ($rows) use ($stay) {
            $rows = collect($rows)->keyBy('type');
            expect($rows['services_receipt']['localUrl'])->toBe(route('reservations.fiscal-report', $stay).'#document-services_receipt-TEST-RECEIPT-169')
                ->and($rows['services_receipt']['viewerUrl'])->toBe('https://vfms-viewer.example/receipt-form/TEST-RECEIPT-169')
                ->and($rows['services_receipt']['mode'])->toBe('sandbox');

            return true;
        }));
    $response->assertDontSee('P1234567')->assertDontSee('P7654321')->assertDontSee('documentNo');
    $this->get(route('receipts.index', ['tab' => 'non_fiscal']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.total', 1)
        ->where('documents.data.0.localUrl', route('receipts.show', $receipt))
        ->where('documents.data.0.mode', null));
});

test('reports-only readers can open issued receipts and early documents without reservation or identity access', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    config(['services.vfms.test_viewer_url' => 'https://vfms-viewer.example']);
    $tenant = twoTenants()->a;
    [$stay, $receipt] = receiptsRegisterStay($tenant);
    $reader = $tenant->member(OrganizationRole::Auditor);
    inTenant($tenant->property, function () use ($tenant, $stay, $reader) {
        $reader->syncRoles([]);
        $reader->givePermissionTo(OrganizationPermission::ReportsView->value);
        app(GuestLeaves::class)->handle($tenant->owner, $stay, ReservationGuest::query()->orderByDesc('id')->firstOrFail()->guest_id);
    });
    $this->actingAs($reader)->withSession([
        'tenancy.organization_id' => $tenant->organization->id,
        'tenancy.property_id' => $tenant->property->id,
    ]);
    $this->get(route('reservations.show', $stay))->assertForbidden();
    $this->get(route('receipts.show', $receipt))->assertOk()->assertDontSee('P1234567');
    $response = $this->get(route('reservations.fiscal-report', $stay))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status', 'waiting')
        ->where('report', null)
        ->where('revealIdentityUrl', null)
        ->has('serviceReceipts', 1)
        ->where('serviceReceipts.0.number', 'TEST-RECEIPT-169')
        ->where('serviceReceipts.0.currency', 'USD')
        ->where('serviceReceipts.0.portalUrl', 'https://vfms-viewer.example/receipt-form/TEST-RECEIPT-169')
        ->has('guestReports', 1)
        ->where('guestReports.0.number', 'TEST-CHECKOUT-169-2')
        ->where('guestReports.0.departedEarly', true)
        ->where('guestReports.0.identity', null));
    $response->assertDontSee('P1234567')->assertDontSee('P7654321')->assertDontSee('documentName');
});

test('register supports an optional upper date without requiring a lower date', function () {
    $tenant = twoTenants()->a;
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id,
        'tenancy.property_id' => $tenant->property->id,
    ])->getJson(route('receipts.index', ['to' => '2026-12-10']))
        ->assertOk();
});

test('register accepts the all filter choices submitted by its form', function () {
    $tenant = twoTenants()->a;
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id,
        'tenancy.property_id' => $tenant->property->id,
    ])->getJson(route('receipts.index', ['from' => '', 'to' => '', 'type' => 'all', 'zra_status' => 'all']))
        ->assertOk();
});

test('register combines filters in property local time and keeps them on pagination links', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 23:59:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    $tenant = twoTenants()->a;
    [$stay] = receiptsRegisterStay($tenant);
    $this->travelTo(CarbonImmutable::parse('2026-12-11 00:01:00', 'Africa/Dar_es_Salaam'));
    inTenant($tenant->property, function () use ($tenant) {
        foreach (range(1, 51) as $ignored) {
            app(RecordPayment::class)->handle($tenant->owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => 100, 'currency' => 'USD']);
        }
    });
    $this->actingAs($tenant->owner)->withSession([
        'tenancy.organization_id' => $tenant->organization->id,
        'tenancy.property_id' => $tenant->property->id,
    ]);
    $filters = ['tab' => 'non_fiscal', 'from' => '2026-12-11', 'to' => '2026-12-11', 'type' => 'non_fiscal', 'zra_status' => 'not_applicable'];
    $response = $this->get(route('receipts.index', $filters))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters', $filters)
        ->where('documents.total', 51)
        ->has('documents.data', 50)
        ->where('documents.data.0.issuedAt', '2026-12-10T21:01:00+00:00'));
    $next = $response->viewData('page')['props']['documents']['next_page_url'];
    parse_str(parse_url($next, PHP_URL_QUERY), $query);
    expect($query)->toEqual([...$filters, 'page' => '2']);
    $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.total', 51)->has('documents.data', 1));
    $this->get(route('receipts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.total', 1)->where('tabCounts.non_fiscal', 52));
    $this->get(route('receipts.index', ['type' => 'services_receipt', 'zra_status' => 'sandbox']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents.total', 1));
    $fiscal = $this->get(route('receipts.index', ['type' => 'services_receipt']))->viewData('page')['props']['documents']['data'][0]['number'];
    $this->get(route('receipts.index', ['number' => ' '.strtolower(substr($fiscal, 1, 6)).' ']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents.total', 1)->where('documents.data.0.number', $fiscal));
    $this->get(route('receipts.index', ['number' => '%']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents.total', 0));
    $this->getJson(route('receipts.index', ['from' => 'not-a-date']))->assertUnprocessable()->assertJsonValidationErrors('from');
    $this->getJson(route('receipts.index', ['from' => '2026-12-11', 'to' => '2026-12-10']))->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson(route('receipts.index', ['type' => 'advance']))->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->getJson(route('receipts.index', ['zra_status' => 'approved']))->assertUnprocessable()->assertJsonValidationErrors('zra_status');
});

test('register and document reads refuse sibling properties even for an owner of both', function () {
    $tenants = twoTenants();
    $foreign = [];
    foreach ([[$tenants->a, $tenants->a->otherProperty], [$tenants->b, $tenants->b->property]] as [$tenant, $property]) {
        $foreign[] = inTenant($property, function () use ($tenant) {
            $stay = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
            app(RecordPayment::class)->handle($tenant->owner, Folio::query()->sole(), ['method' => 'card', 'amount' => 700, 'currency' => 'USD']);

            return [$stay, Receipt::query()->sole()];
        });
    }
    $this->actingAs($tenants->a->owner)->withSession([
        'tenancy.organization_id' => $tenants->a->organization->id,
        'tenancy.property_id' => $tenants->a->property->id,
    ]);
    foreach ($foreign as [$stay, $receipt]) {
        foreach (['zra_receipts', 'checkout_reports', 'non_fiscal'] as $tab) {
            $this->get(route('receipts.index', ['tab' => $tab, 'property_id' => $receipt->property_id, 'reservation' => $stay->id, 'receipt' => $receipt->id]))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('documents.total', 0)
                ->where('tabCounts', ['zra_receipts' => 0, 'checkout_reports' => 0, 'non_fiscal' => 0]));
        }
        $this->get(route('receipts.show', $receipt))->assertNotFound();
        $this->get(route('reservations.fiscal-report', $stay))->assertNotFound();
    }
});

test('legacy issued reports remain readable at their document anchors without inventing missing evidence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    $tenant = twoTenants()->a;
    [$stay] = receiptsRegisterStay($tenant);
    inTenant($tenant->property, function () use ($tenant, $stay) {
        app(GuestLeaves::class)->handle($tenant->owner, $stay, ReservationGuest::query()->orderByDesc('id')->firstOrFail()->guest_id);
        $departure = OutboxMessage::query()->where('event', 'guestDeparture')->sole();
        $departure->setAttribute('provider_references', array_diff_key($departure->getAttribute('provider_references'), ['reportUrls' => true]))->save();
        DB::table('issued_documents')->where('outbox_message_id', $departure->id)->delete();
        DB::table('outbox_attempts')->where('outbox_message_id', $departure->id)->delete();
        app(IssuedDocuments::class)->project($departure);
    });
    $this->actingAs($tenant->member(OrganizationRole::Auditor))->withSession([
        'tenancy.organization_id' => $tenant->organization->id,
        'tenancy.property_id' => $tenant->property->id,
    ])->get(route('reservations.fiscal-report', $stay))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('report', null)
        ->has('guestReports', 1)
        ->where('guestReports.0.number', 'TEST-CHECKOUT-169-2')
        ->where('guestReports.0.name', null)
        ->where('guestReports.0.identity', null)
        ->where('guestReports.0.portalUrl', null)
        ->where('guestReports.0.qrSvg', null));
});
