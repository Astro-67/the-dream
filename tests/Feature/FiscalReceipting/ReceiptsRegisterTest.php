<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
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
            ...$details, 'adults' => 2, 'children' => 0, 'room_id' => Room::query()->sole()->id,
        ]);
        foreach ([['Anna Berg', 'SECRET-PASSPORT-230'], ['Ola Berg', 'PRIVATE-PASSPORT-230']] as [$name, $number]) {
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
    $response->assertDontSee('SECRET-PASSPORT-230')->assertDontSee('PRIVATE-PASSPORT-230')->assertDontSee('documentNo');
});
