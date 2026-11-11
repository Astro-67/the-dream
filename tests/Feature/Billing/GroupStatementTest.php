<?php

use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Actions\ReplaceIdentityDocuments;
use App\Domains\Guests\Models\Guest;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('group statement prints a standalone reservation without identity documents or staff notes', function () {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10', 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]);
    $reservation = inTenant($t->a->property, function () use ($t) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $t->a->property);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [
            ...BookingForm::details($t->a->owner), 'adults' => 1, 'children' => 0,
            'paying_institution_type' => 'business', 'paying_institution_name' => 'Island Research',
            'paying_institution_zrb_number' => 'ZRB-245',
        ]);
        app(AttachReservationGuest::class)->handle($t->a->owner, $reservation, ['name' => 'Amina', 'notes' => 'Private group note']);
        app(ReplaceIdentityDocuments::class)->handle(Guest::query()->sole(), [['type' => 'passport', 'number' => 'SECRET245', 'nationality' => 'TZ']]);
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        app(PostRoomCharge::class)->handle($t->a->owner, $reservation, '2026-12-10');

        return $reservation;
    });

    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.group-statement', $reservation))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('reservations/group-statement')
            ->where('property.name', $t->a->property->name)
            ->where('payingInstitution.name', 'Island Research')->where('payingInstitution.zrbNumber', 'ZRB-245')
            ->where('group.arrival', '2026-12-10')->where('group.departure', '2026-12-12')
            ->where('group.rooms', 1)->where('group.guests', 1)
            ->has('rooms', 1)->where('rooms.0.reservation.reservation_number', $reservation->reservation_number)
            ->where('rooms.0.folios.0.summary.balance', 10500)
            ->where('rooms.0.folios.0.postings.0.tax_lines.0.name', 'Infrastructure levy')
            ->where('rooms.0.reports', [])
            ->has('totals', 1)->where('totals.0.currency', 'USD')->where('totals.0.balance', 10500))
        ->assertDontSee('SECRET245')->assertDontSee('Private group note');
});
