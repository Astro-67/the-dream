<?php

use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\Guests\Actions\CreateGuest;

// protects=document and country spelling match accepted VFMS requests (#170); fails_when=lowercase names or ISO codes are sent; no_claim=production acceptance of arbitrary identity documents.
test('VFMS spells each supported document and country as the authority expects', function (string $type, string $name, string $country, string $expectedCountry) {
    [$owner, , $property] = organizationWithOwner();
    $profile = inTenant($property, function () use ($owner, $type, $country) {
        $guest = app(CreateGuest::class)->handle($owner, [
            'name' => 'Test Guest',
            'documents' => [['type' => $type, 'number' => 'TEST-170', 'nationality' => $country]],
        ]);

        return app(VfmsPayloads::class)->guest($guest);
    });

    expect($profile)->toEqual([
        'documentName' => $name,
        'documentNo' => 'TEST-170',
        'country' => $expectedCountry,
        'fullName' => 'Test Guest',
    ]);
})->with([
    ['passport', 'PASSPORT', 'IT', 'ITALY'],
    ['nida', 'NIDA', 'TZ', 'TANZANIA'],
    ['zanid', 'ZANID', 'TZ', 'TANZANIA'],
]);
