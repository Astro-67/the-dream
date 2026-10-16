<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

test('tenant scoped report aggregates have covering indexes', function (string $table, array $columns) {
    expect(Schema::hasIndex($table, $columns))->toBeTrue();
})->with([
    'recorded history' => ['reservation_lifecycle_events', ['organization_id', 'property_id', 'reservation_id', 'type', 'business_date']],
    'reservation status and dates' => ['reservations', ['organization_id', 'property_id', 'status', 'arrival', 'departure']],
    'booked value' => ['reservation_price_lines', ['organization_id', 'property_id', 'date', 'reservation_id', 'currency', 'amount']],
    'reservation price lookups' => ['reservation_price_lines', ['reservation_id', 'date', 'organization_id', 'property_id', 'currency', 'amount']],
    'folio ownership and reservation joins' => ['folios', ['organization_id', 'property_id', 'reservation_id', 'currency']],
    'folio balances' => ['postings', ['organization_id', 'property_id', 'folio_id', 'amount']],
    'payments' => ['postings', ['organization_id', 'property_id', 'type', 'business_date', 'method', 'currency', 'cashier_shift_id', 'amount']],
]);

test('qualification ranges stay on the stored business date across midnight', function () {
    require_once base_path('scripts/qualification/verify-seed.php');
    [, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-10-15']);
    CarbonImmutable::setTestNow('2026-10-16 03:00:00');
    try {
        expect(qualificationDate()->toDateString())->toBe('2026-10-15');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
