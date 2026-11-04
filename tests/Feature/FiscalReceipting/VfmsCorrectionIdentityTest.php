<?php

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use Illuminate\Validation\ValidationException;

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

test('correction identity refuses Sandbox and missing or invalid property business data', function (string $field, mixed $value, string $error) {
    $property = twoTenants()->a->property;
    inTenant($property, function () use ($property, $field, $value) {
        $property->forceFill(['email' => 'desk@example.test', 'phone' => '+255777123456', 'z_number' => 'Z0123456789-5940']);
        $credential = VfmsCredential::for($property)->fill(['mode' => VfmsMode::Live, 'unit_id' => 231]);
        if (in_array($field, ['mode', 'unit_id'], true)) {
            $credential->setAttribute($field, $value);
        } else {
            $property->setAttribute($field, $value);
        }
        $credential->save();
    });

    try {
        app(VfmsPayloads::class)->correctionIdentity($property);
        test()->fail('Invalid correction identity was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($error);
    }
})->with([
    'Sandbox' => ['mode', VfmsMode::Sandbox, 'mode'],
    'missing unit' => ['unit_id', null, 'unitId'],
    'invalid unit' => ['unit_id', 0, 'unitId'],
    'missing email' => ['email', '', 'email'],
    'invalid email' => ['email', 'not-email', 'email'],
    'missing phone' => ['phone', '', 'phone_no'],
    'missing Z-number' => ['z_number', null, 'zrb_number'],
]);
