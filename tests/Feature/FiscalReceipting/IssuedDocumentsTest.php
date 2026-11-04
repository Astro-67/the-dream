<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\FrontDesk\Actions\GuestLeaves;
use App\Domains\Guests\Models\Guest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    config(['services.vfms.test_viewer_url' => 'https://vfms-viewer.example']);
});

/** A two-guest Sandbox stay with one extra; Ola leaves early when $earlyDeparture, then the stay checks out. */
function issuedDocumentsStay(TenantFixture $tenant, bool $earlyDeparture = true): Reservation
{
    $reservation = inHouseForCheckOut($tenant);
    inTenant($tenant->property, fn () => app(PostExtraCharge::class)->handle(
        $tenant->owner, Folio::query()->where('reservation_id', $reservation->id)->sole(),
        ['category' => 'bar', 'description' => 'Water', 'quantity' => 2, 'unit_price' => 1000],
    ));
    if ($earlyDeparture) {
        inTenant($tenant->property, fn () => app(GuestLeaves::class)->handle($tenant->owner, $reservation, Guest::query()->where('name', 'Ola Berg')->sole()->id));
    }

    return checkOutOnDeparture($tenant, $reservation);
}

/** @return list<array<string, mixed>> the property's issued fiscal rows as the register shows them, less their rebuildable IDs */
function issuedFiscalRows(TenantFixture $tenant, array $filters = []): array
{
    return inTenant($tenant->property, fn () => collect(app(IssuedDocuments::class)->register($tenant->property, $filters)->items())
        ->where('type', '!=', 'non_fiscal')->sortBy('number')->map(fn (array $row) => array_diff_key($row, ['id' => true]))->values()->all());
}

function issuedDocumentsSnapshot(TenantFixture $tenant): array
{
    return inTenant($tenant->property, fn () => [
        OutboxMessage::query()->orderBy('id')->get()->map(fn (OutboxMessage $message) => [$message->status, $message->attempt_count, $message->getAttribute('provider_references')])->all(),
        Posting::query()->orderBy('id')->pluck('vfms_receipt_number', 'id')->all(),
    ]);
}

// protects=one issued row per accepted services receipt and per guest report, an early departure counted once (#229); fails_when=a report repeats, a whole-stay total is copied to each guest or stored links change; no_claim=ZRA's production numbering.
test('accepted services receipts and guest reports each project one issued row', function () {
    $tenant = twoTenants()->a;
    issuedDocumentsStay($tenant);

    expect(issuedFiscalRows($tenant))->toHaveCount(3)
        ->and(array_map(fn (array $row) => array_intersect_key($row, array_flip(['type', 'number', 'guest', 'amount', 'currency', 'zraStatus', 'viewerUrl'])), issuedFiscalRows($tenant)))->toEqual([
            ['type' => 'checkout_report', 'number' => 'TEST-CHECKOUT-169', 'guest' => 'Anna Berg', 'amount' => 22000, 'currency' => 'USD', 'zraStatus' => 'sandbox', 'viewerUrl' => 'https://vfms-viewer.example/checkout-report/guest/TEST-CHECKOUT-169'],
            ['type' => 'checkout_report', 'number' => 'TEST-CHECKOUT-169-2', 'guest' => 'Ola Berg', 'amount' => 22000, 'currency' => 'USD', 'zraStatus' => 'sandbox', 'viewerUrl' => 'https://vfms-viewer.example/checkout-report/guest/TEST-CHECKOUT-169-2'],
            ['type' => 'services_receipt', 'number' => 'TEST-RECEIPT-169', 'guest' => 'Anna Berg', 'amount' => 2000, 'currency' => 'USD', 'zraStatus' => 'sandbox', 'viewerUrl' => 'https://vfms-viewer.example/receipt-form/TEST-RECEIPT-169'],
        ]);
    $json = json_encode(issuedFiscalRows($tenant));
    expect($json)->not->toContain('P1234567', 'P7654321', 'TEST-TOKEN');
});

// protects=historical evidence is projected by a rerunnable catch-up without changing it (#229); fails_when=a rerun duplicates rows, an interrupted run loses one, or filings/Posting numbers change.
test('the catch-up backfills history idempotently and leaves filings unchanged', function () {
    $tenant = twoTenants()->a;
    issuedDocumentsStay($tenant);
    $expected = issuedFiscalRows($tenant);
    $before = issuedDocumentsSnapshot($tenant);
    // History from before the projection existed, then a run interrupted after one row.
    inTenant($tenant->property, fn () => DB::table('issued_documents')->delete());
    $this->artisan('vfms:issued-documents')->assertSuccessful();
    inTenant($tenant->property, fn () => DB::table('issued_documents')->where('number', 'TEST-CHECKOUT-169')->delete());
    $this->artisan('vfms:issued-documents')->assertSuccessful();
    $this->artisan('vfms:issued-documents')->assertSuccessful();

    expect(issuedFiscalRows($tenant))->toEqual($expected)
        ->and(issuedDocumentsSnapshot($tenant))->toEqual($before);
});

// protects=a duplicate accepted observation projects nothing new (#229); fails_when=a second accepted attempt or a repeated projection adds rows.
test('duplicate accepted observations add no rows', function () {
    $tenant = twoTenants()->a;
    issuedDocumentsStay($tenant, earlyDeparture: false);
    inTenant($tenant->property, function () {
        $checkout = OutboxMessage::query()->where('event', 'checkout')->sole();
        $first = $checkout->attempts()->sole();
        $checkout->attempts()->create(['attempt_number' => 2, 'outcome' => 'accepted', 'request_body' => $first->request_body, 'response_body' => $first->response_body]);
        OutboxMessage::query()->each(fn (OutboxMessage $message) => app(IssuedDocuments::class)->project($message));
    });

    expect(issuedFiscalRows($tenant))->toHaveCount(3);
});

// protects=projection failure never replays a filing (#229); fails_when=a failing projection changes delivery status or a later catch-up does not heal it.
test('a failed projection leaves the filing delivered and the catch-up heals it', function () {
    $tenant = twoTenants()->a;
    app()->bind(IssuedDocuments::class, fn () => throw new RuntimeException('projection down'));
    issuedDocumentsStay($tenant, earlyDeparture: false);
    app()->offsetUnset(IssuedDocuments::class);

    inTenant($tenant->property, fn () => expect(OutboxMessage::query()->pluck('status')->unique()->all())->toBe(['delivered'])
        ->and(OutboxMessage::query()->sum('attempt_count'))->toBe(OutboxMessage::query()->count())
        ->and(DB::table('issued_documents')->count())->toBe(0));
    $this->artisan('vfms:issued-documents')->assertSuccessful();

    expect(issuedFiscalRows($tenant))->toHaveCount(3);
});

// protects=missing legacy evidence stays unavailable rather than reconstructed (#229); fails_when=a missing viewer link is rebuilt from config or an unreadable report gets a guessed amount.
test('legacy evidence without stored links or readable reports is marked unavailable', function () {
    $tenant = twoTenants()->a;
    issuedDocumentsStay($tenant, earlyDeparture: false);
    inTenant($tenant->property, function () {
        DB::table('issued_documents')->delete();
        $services = OutboxMessage::query()->where('event', 'services')->sole();
        $services->setAttribute('provider_references', array_diff_key($services->getAttribute('provider_references'), ['receiptUrl' => true]))->save();
        $checkout = OutboxMessage::query()->where('event', 'checkout')->sole();
        $checkout->setAttribute('provider_references', array_diff_key($checkout->getAttribute('provider_references'), ['reportUrls' => true]))->save();
        // An attempt row lost to an old restore: the report numbers survive only in the message references.
        DB::table('outbox_attempts')->where('outbox_message_id', $checkout->id)->delete();
    });
    $this->artisan('vfms:issued-documents')->assertSuccessful();

    expect(array_map(fn (array $row) => [$row['number'], $row['guest'], $row['amount'], $row['viewerUrl']], issuedFiscalRows($tenant)))->toBe([
        ['TEST-CHECKOUT-169', null, null, null],
        ['TEST-CHECKOUT-169-2', null, null, null],
        ['TEST-RECEIPT-169', 'Anna Berg', 2000, null],
    ]);
});

/** Records a card payment of $amount in $currency at the current time; returns its receipt's ID. */
function issuedDocumentsPayment(TenantFixture $tenant, Folio $folio, int $amount, string $currency = 'USD'): string
{
    $property = $tenant->properties[0]->id === $folio->property_id ? $tenant->property : $tenant->otherProperty;

    return inTenant($property, fn () => app(RecordPayment::class)->handle($tenant->owner, $folio, ['method' => 'card', 'amount' => $amount, 'currency' => $currency])->receipt->id);
}

function issuedDocumentsFolio(TenantFixture $tenant, $property = null): Folio
{
    $property ??= $tenant->property;
    $property->update(['status' => PropertyStatus::Live]);

    return inTenant($property, function () use ($tenant) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));

        return Folio::query()->where('reservation_id', $reservation->id)->sole();
    });
}

// protects=the register combines non-fiscal and fiscal documents of one property only, in mixed currencies, newest first (#229); fails_when=a sibling property's document shows, currencies merge or order is unstable.
test('the register combines one property\'s non-fiscal receipts with its fiscal documents', function () {
    $t = twoTenants();
    $stay = issuedDocumentsStay($t->a, earlyDeparture: false);
    $this->travelTo(CarbonImmutable::parse('2026-12-12 15:00:00', 'Africa/Dar_es_Salaam'));
    $folio = inTenant($t->a->property, fn () => Folio::query()->where('reservation_id', $stay->id)->sole());
    $usd = issuedDocumentsPayment($t->a, $folio, 1500);
    $tzs = issuedDocumentsPayment($t->a, inTenant($t->a->property, fn () => app(OpenFolio::class)->handle($t->a->owner, Reservation::query()->findOrFail($folio->reservation_id), 'TZS')), 50000, 'TZS');
    $sibling = issuedDocumentsPayment($t->a, issuedDocumentsFolio($t->a, $t->a->otherProperty), 700);
    issuedDocumentsPayment($t->b, issuedDocumentsFolio($t->b), 900);

    $rows = inTenant($t->a->property, fn () => app(IssuedDocuments::class)->register($t->a->property, [])->items());

    expect(array_column($rows, 'id'))->toContain($usd, $tzs)->not->toContain($sibling)
        ->and(count($rows))->toBe(5)
        ->and(array_slice(array_map(fn (array $row) => [$row['id'], $row['type'], $row['amount'], $row['currency'], $row['zraStatus']], $rows), 0, 2))->toBe([
            [$tzs, 'non_fiscal', 50000, 'TZS', 'not_applicable'],
            [$usd, 'non_fiscal', 1500, 'USD', 'not_applicable'],
        ]);
    $issued = array_column($rows, 'issuedAt');
    expect($issued)->toBe(collect($issued)->sortDesc()->values()->all());
});

// protects=filters use the property's local day, type and ZRA status, paginating the combined register at 50 (#229); fails_when=a UTC boundary leaks a document into the wrong day, a filter is ignored or invalid input passes.
test('the register filters by local date, type and ZRA status and paginates at 50', function () {
    $t = twoTenants();
    $stay = issuedDocumentsStay($t->a, earlyDeparture: false);
    $folio = inTenant($t->a->property, fn () => Folio::query()->where('reservation_id', $stay->id)->sole());
    $this->travelTo(CarbonImmutable::parse('2026-12-13 23:30:00', 'Africa/Dar_es_Salaam'));
    $late = issuedDocumentsPayment($t->a, $folio, 100);
    $this->travelTo(CarbonImmutable::parse('2026-12-14 00:30:00', 'Africa/Dar_es_Salaam'));
    $next = issuedDocumentsPayment($t->a, $folio, 100);
    foreach (range(1, 48) as $ignored) {
        issuedDocumentsPayment($t->a, $folio, 100);
    }
    $register = fn (array $filters) => inTenant($t->a->property, fn () => app(IssuedDocuments::class)->register($t->a->property, $filters));

    expect(array_column($register(['from' => '2026-12-13', 'to' => '2026-12-13'])->items(), 'id'))->toBe([$late])
        ->and(array_column($register(['from' => '2026-12-14'])->items(), 'id'))->toContain($next)->not->toContain($late)
        ->and($register(['type' => 'checkout_report'])->total())->toBe(2)
        ->and($register(['zra_status' => 'sandbox'])->total())->toBe(3)
        ->and($register(['zra_status' => 'live'])->total())->toBe(0)
        ->and($register(['zra_status' => 'not_applicable'])->total())->toBe(50);
    $all = $register([]);
    expect($all->total())->toBe(53)->and($all->perPage())->toBe(50)->and(count($all->items()))->toBe(50);
    expect(fn () => $register(['from' => '13/12/2026']))->toThrow(ValidationException::class)
        ->and(fn () => $register(['type' => 'advance']))->toThrow(ValidationException::class);
});
