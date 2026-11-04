<?php

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;

test('correction identity reuses property contacts full Z-number and accepted business name', function (?string $businessName) {
    $property = twoTenants()->a->property;
    inTenant($property, function () use ($property) {
        $property->update(['name' => 'Property name', 'email' => 'desk@example.test', 'phone' => '+255777123456', 'z_number' => 'Z0123456789-5940']);
        VfmsCredential::for($property)->fill(['mode' => VfmsMode::Live, 'unit_id' => PHP_INT_MAX, 'registration_id' => 'UNRELATED-REG'])->save();
    });

    $identity = app(VfmsPayloads::class)->correctionIdentity($property, ['businessName' => $businessName]);

    expect($identity)->toEqual([
        'b_unit_name' => $businessName ?: 'Property name',
        'email' => 'desk@example.test',
        'phone_no' => '+255777123456',
        'zrb_number' => 'Z0123456789-5940',
        'unitId' => PHP_INT_MAX,
    ]);
})->with(['Accepted business name', null, '']);
