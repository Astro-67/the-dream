<?php

use Illuminate\Support\Facades\Schema;

test('tenant scoped report aggregates have covering indexes', function (string $table, array $columns) {
    expect(Schema::hasIndex($table, $columns))->toBeTrue();
})->with([
    'recorded history' => ['reservation_lifecycle_events', ['organization_id', 'property_id', 'reservation_id', 'type', 'business_date']],
    'reservation status and dates' => ['reservations', ['organization_id', 'property_id', 'status', 'arrival', 'departure']],
    'booked value' => ['reservation_price_lines', ['organization_id', 'property_id', 'date', 'reservation_id', 'currency', 'amount']],
    'folio balances' => ['postings', ['organization_id', 'property_id', 'folio_id', 'amount']],
    'payments' => ['postings', ['organization_id', 'property_id', 'type', 'business_date', 'method', 'currency', 'cashier_shift_id', 'amount']],
]);
