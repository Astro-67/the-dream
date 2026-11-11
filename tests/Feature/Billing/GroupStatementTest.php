<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Actions\ReplaceIdentityDocuments;
use App\Domains\Guests\Models\Guest;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

beforeEach(fn () => Http::preventStrayRequests());

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

test('group statement uses reservation view permission', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($actor)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.group-statement', $reservation));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(array_map(fn (OrganizationRole $role) => [$role, $role !== OrganizationRole::Housekeeping], OrganizationRole::cases()));

test('group statement cannot cross a property or follow stale property intent', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    inTenant($t->a->property, fn () => $reservation->update(['booking_group_id' => '01GROUP24500000000000000000']));
    $sibling = inTenant($t->a->otherProperty, function () use ($t, $reservation) {
        $sibling = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $sibling->update(['booking_group_id' => $reservation->booking_group_id, 'reservation_number' => 'SIBLING-245']);

        return $sibling;
    });
    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.group-statement', $reservation))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rooms', 1)->where('group.rooms', 1));
    $this->get(route('reservations.group-statement', [$sibling, '_property' => $t->a->otherProperty->id]))->assertNotFound();
    $this->withSession(['tenancy.property_id' => $t->a->otherProperty->id])
        ->get(route('reservations.group-statement', [$reservation, '_property' => $t->a->property->id]))->assertNotFound();
});

test('group statement lists only stored accepted checkout and guest departure numbers without exposing their documents', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        foreach ([
            ['guestDeparture', 'delivered', ['reportNumbers' => '["DEPARTED-245"]']],
            ['checkout', 'delivered', ['reportNumbers' => '["DEPARTED-245","CHECKOUT-245","SECOND-245"]']],
            ['checkout', 'delivered', ['reportNo' => 'LEGACY-245']],
            ['checkout', 'unknown', ['reportNumbers' => '["UNKNOWN-245"]']],
            ['checkout', 'refused', ['reportNo' => 'REFUSED-245']],
            ['checkout', 'pending', ['reportNo' => 'PENDING-245']],
            ['checkIn', 'delivered', ['reportNo' => 'CHECKIN-245']],
        ] as $index => [$event, $status, $references]) {
            $message = app(Outbox::class)->record(VfmsAdapter::INTEGRATION, $reservation, $event, [
                'body' => ['documentNumberList' => [['documentNumber' => 'PRIVATE-FISCAL-DOCUMENT']]],
            ], 'group-statement-'.$index);
            $message->forceFill(['status' => $status, 'provider_references' => $references])->save();
        }

        return $reservation;
    });

    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.group-statement', $reservation))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rooms.0.reports', ['DEPARTED-245', 'CHECKOUT-245', 'SECOND-245', 'LEGACY-245']))
        ->assertDontSee('PRIVATE-FISCAL-DOCUMENT')->assertDontSee('UNKNOWN-245')->assertDontSee('REFUSED-245')
        ->assertDontSee('PENDING-245')->assertDontSee('CHECKIN-245');
    Http::assertNothingSent();
});

test('group statement includes every member and folio and keeps posted totals apart by currency', function () {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10', 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]);
    [$first, $second] = inTenant($t->a->property, function () use ($t) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $t->a->property);
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        app(CreateReservation::class)->handle($t->a->owner, [...$details, 'rooms' => 2, 'adults' => 1, 'children' => 0]);
        $members = Reservation::query()->orderBy('reservation_number')->get();
        foreach ($members as $member) {
            app(AttachReservationGuest::class)->handle($t->a->owner, $member, ['name' => 'Guest '.$member->reservation_number]);
            app(ReplaceIdentityDocuments::class)->handle(Guest::query()->where('name', 'Guest '.$member->reservation_number)->sole(), [['type' => 'passport', 'number' => 'PRIVATE-'.$member->reservation_number, 'nationality' => 'TZ']]);
            $member->update(['status' => ReservationStatus::CheckedIn]);
            app(PostRoomCharge::class)->handle($t->a->owner, $member, '2026-12-10');
        }
        $usd = app(OpenFolio::class)->handle($t->a->owner, $members[0], 'USD');
        app(PostExtraCharge::class)->handle($t->a->owner, $usd, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1000]);
        app(RecordPayment::class)->handle($t->a->owner, $usd, ['method' => 'bank_transfer', 'amount' => 1000, 'currency' => 'USD']);
        $tzs = app(OpenFolio::class)->handle($t->a->owner, $members[1], 'TZS');
        app(PostExtraCharge::class)->handle($t->a->owner, $tzs, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 100000]);
        app(RecordPayment::class)->handle($t->a->owner, $tzs, ['method' => 'bank_transfer', 'amount' => 40000, 'currency' => 'TZS']);
        app(RecordPayment::class)->handle($t->a->owner, Folio::query()->where('reservation_id', $members[1]->id)->where('currency', 'USD')->sole(), ['method' => 'bank_transfer', 'amount' => 10000, 'currency' => 'USD']);
        $members[1]->update(['departure' => '2026-12-13']);
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($details['room_type_id'], ['number' => '103']));
        app(CreateReservation::class)->handle($t->a->owner, $details);

        return $members->all();
    });

    foreach ([$first, $second] as $opened) {
        $this->actingAs($t->a->owner)
            ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
            ->get(route('reservations.group-statement', $opened))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('group.rooms', 2)->where('group.guests', 2)
                ->where('group.arrival', '2026-12-10')->where('group.departure', '2026-12-13')
                ->where('payingInstitution', null)
                ->has('rooms', 2)
                ->where('rooms.0.reservation.reservation_number', $first->reservation_number)
                ->where('rooms.1.reservation.reservation_number', $second->reservation_number)
                ->has('rooms.0.folios', 2)->has('rooms.1.folios', 2)
                ->has('totals', 2)
                ->where('totals.0.currency', 'TZS')->where('totals.0.charges', 100000)
                ->where('totals.0.paid', 40000)->where('totals.0.balance', 60000)
                ->where('totals.1.currency', 'USD')->where('totals.1.charges', 22000)
                ->where('totals.1.paid', 11000)->where('totals.1.balance', 11000));
    }
});
