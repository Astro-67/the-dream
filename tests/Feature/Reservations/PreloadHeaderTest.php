<?php

use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Tests\Support\BookingForm;

// LiteSpeed in production answers 500 when the Link header passes ~4 KB (every reservation page did on 2026-11-21).
test('reservation detail keeps the preload Link header under the LiteSpeed limit', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $link = $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertOk()->headers->get('Link');

    expect($link)->not->toBeNull()->and(substr_count($link, '>; rel='))->toBeLessThanOrEqual(20)->and(strlen($link))->toBeLessThan(3500);
});
