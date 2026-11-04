<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
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
use Carbon\CarbonImmutable;
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
