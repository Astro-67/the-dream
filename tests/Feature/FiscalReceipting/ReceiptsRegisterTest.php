<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
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
                ->where('filters', [])
                ->where('documents.data', [])
                ->where('documents.total', 0));
        }
    }
});

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
        ->where('documents.total', 2)
        ->where('documents.data', function ($rows) use ($stay, $receipt) {
            $rows = collect($rows)->keyBy('type');
            expect($rows['non_fiscal']['localUrl'])->toBe(route('receipts.show', $receipt))
                ->and($rows['non_fiscal']['mode'])->toBeNull()
                ->and($rows['services_receipt']['localUrl'])->toBe(route('reservations.fiscal-report', $stay).'#document-services_receipt-TEST-RECEIPT-169')
                ->and($rows['services_receipt']['viewerUrl'])->toBe('https://vfms-viewer.example/receipt-form/TEST-RECEIPT-169')
                ->and($rows['services_receipt']['mode'])->toBe('sandbox');

            return true;
        }));
    $response->assertDontSee('P1234567')->assertDontSee('P7654321')->assertDontSee('documentNo');
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
    $filters = ['from' => '2026-12-11', 'to' => '2026-12-11', 'type' => 'non_fiscal', 'zra_status' => 'not_applicable'];
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
    $this->get(route('receipts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents.total', 53));
    $this->get(route('receipts.index', ['type' => 'services_receipt', 'zra_status' => 'sandbox']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents.total', 1));
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
        $this->get(route('receipts.index', ['property_id' => $receipt->property_id, 'reservation' => $stay->id, 'receipt' => $receipt->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents.total', 0));
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
