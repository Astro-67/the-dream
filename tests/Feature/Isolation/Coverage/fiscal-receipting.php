<?php

use App\Domains\FiscalReceipting\Actions\SaveVfmsCredentials;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Actions\CreateReservation;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/** Bravo saves its own credentials first, so an attack has something to overwrite. */
$bravoCredentials = function (TwoTenants $t): void {
    inTenant($t->b->property, fn () => app(SaveVfmsCredentials::class)->handle($t->b->owner, $t->b->property, 'BRAVO-INT', 'BRAVO-REG', 'BRAVO-TOKEN'));
};

return [
    'properties.vfms.update' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoCredentials) {
        $bravoCredentials($t);
        $form = ['integration_id' => 'ALPHA-INT', 'registration_id' => 'ALPHA-REG', 'token' => 'ALPHA-TOKEN'];
        $responses = [
            $this->actingAs($t->a->owner)->put(route('properties.vfms.update', $t->b->property->id), [...$form, '_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('properties.vfms.update', $t->b->property->id), [...$form, '_property' => $t->b->property->id])->assertForbidden(),
        ];
        $saved = VfmsCredential::for($t->b->property);
        expect([$saved->integration_id, $saved->token])->toBe(['BRAVO-INT', 'BRAVO-TOKEN']);

        return $responses;
    }),
    'properties.vfms.mode' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoCredentials) {
        $bravoCredentials($t);
        $form = ['mode' => 'live', 'confirmation' => 'LIVE', 'reason' => 'Attack'];
        $responses = [
            $this->actingAs($t->a->owner)->put(route('properties.vfms.mode', $t->b->property->id), [...$form, '_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('properties.vfms.mode', $t->b->property->id), [...$form, '_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect(VfmsCredential::for($t->b->property)->mode)->toBe(VfmsMode::Sandbox);

        return $responses;
    }),
    'reservations.fiscal-report' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.fiscal-report', $reservation->reservation_number))->assertNotFound();
        }

        return $responses;
    }),
];
