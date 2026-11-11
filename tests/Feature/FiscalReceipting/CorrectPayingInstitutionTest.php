<?php

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\PayingInstitutionType;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    Http::swap(new Factory);
    Http::preventStrayRequests();
});

/**
 * A two-room booking group paid by a registered business, rooms 101 and 102.
 *
 * @return list<Reservation>
 */
function institutionGroup(TenantFixture $tenant): array
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CreateReservation::class)->handle($tenant->owner, [...$details, 'rooms' => 2, 'adults' => 2, 'children' => 0,
            'paying_institution_type' => 'business', 'paying_institution_name' => 'Island Supplies Ltd', 'paying_institution_zrb_number' => 'Z-WRONG']);

        return Reservation::query()->orderBy('reservation_number')->get()->all();
    });
}

function correctInstitution($test, TenantFixture $tenant, Reservation $reservation, array $form = ['paying_institution_type' => 'business', 'paying_institution_name' => 'Island Supplies Ltd', 'paying_institution_zrb_number' => 'Z-RIGHT'])
{
    return $test->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->patch(route('reservations.paying-institution', $reservation), [...$form, '_property' => $tenant->property->id]);
}

/** @return array<string, mixed> */
function institutionOf(TenantFixture $tenant, Reservation $reservation): array
{
    return inTenant($tenant->property, fn () => $reservation->fresh()->only(['paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number']));
}

// protects=staff can correct a booking group's paying institution before check-in, audited and unfiled (spec #242 stories 5-6); fails_when=a member keeps the old value, the change is unrecorded, or it files with VFMS; no_claim=checked-in stays.
test('correcting a confirmed group updates every room, records the change and files nothing', function () {
    $t = twoTenants();
    [$first, $second] = institutionGroup($t->a);

    correctInstitution($this, $t->a, $first)->assertRedirect(route('reservations.show', $first))->assertSessionHasNoErrors();

    foreach ([$first, $second] as $member) {
        expect(institutionOf($t->a, $member))->toEqual(['paying_institution_type' => PayingInstitutionType::Business, 'paying_institution_name' => 'Island Supplies Ltd', 'paying_institution_zrb_number' => 'Z-RIGHT']);
        $event = inTenant($t->a->property, fn () => $member->lifecycleEvents()->where('type', 'paying_institution_changed')->sole());
        expect($event->actor_id)->toBe($t->a->owner->id)
            ->and($event->snapshot['before'])->toEqual(['paying_institution_type' => 'business', 'paying_institution_name' => 'Island Supplies Ltd', 'paying_institution_zrb_number' => 'Z-WRONG'])
            ->and($event->snapshot['after'])->toEqual(['paying_institution_type' => 'business', 'paying_institution_name' => 'Island Supplies Ltd', 'paying_institution_zrb_number' => 'Z-RIGHT']);
    }
    expect(inTenant($t->a->property, fn () => OutboxMessage::query()->count()))->toBe(0);
    Http::assertNothingSent();
});
