<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use Illuminate\Validation\ValidationException;

test('opening a shift records a float in each currency, one open shift per user and property', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $shift = app(OpenCashierShift::class)->handle($owner, ['TZS' => 500000, 'USD' => 10000]);

        expect($shift->status)->toBe('open')->and($shift->user_id)->toBe($owner->id)
            ->and($shift->floats)->toEqual(['TZS' => 500000, 'USD' => 10000]);
        expect(fn () => app(OpenCashierShift::class)->handle($owner, ['USD' => 100]))->toThrow(ValidationException::class);
    });
});
