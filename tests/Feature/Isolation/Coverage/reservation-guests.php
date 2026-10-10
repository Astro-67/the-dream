<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\CreateReservation;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'guests.matches' => RouteCoverage::attempt(function (TwoTenants $t) {
        $guest = inTenant($t->b->property, fn () => app(CreateGuest::class)->handle($t->b->owner, ['name' => 'Bravo Secret Guest', 'email' => 'secret@bravo.test', 'documents' => [['type' => 'passport', 'number' => 'BRAVO-DOC-55']]]));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->postJson(route('guests.matches'), ['_property' => $t->a->property->id, 'name' => 'Bravo Secret Guest', 'email' => 'secret@bravo.test', 'document_number' => 'BRAVO-DOC-55'])->assertDontSee($guest->id)->assertDontSee('Bravo Secret Guest')->assertDontSee('BRAVO-DOC-55');
        }

        return $responses;
    }),
    'reservations.guests.create' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));

        return $this->actingAs($t->a->owner)->get(route('reservations.guests.create', $reservation))->assertNotFound();
    }),
    'reservations.guests.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $response = $this->actingAs($t->a->owner)->post(route('reservations.guests.store', $reservation), ['_property' => $t->a->property->id, 'name' => 'Hijacked'])->assertNotFound();
        expect(inTenant($t->b->property, fn () => ReservationGuest::query()->count()))->toBe(0);

        return $response;
    }),
];
