<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\PayingInstitutionType;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\DrainOutboxMessage;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

/** Checks a group member into its room with two registered guests; ZRA's answer comes from the test's fake. */
function checkInInstitutionMember(TenantFixture $tenant, Reservation $member, string $room): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $member, $room) {
        app(AssignRoom::class)->handle($tenant->owner, $member, Room::query()->where('number', $room)->sole()->id);
        foreach (['A', 'B'] as $guest) {
            $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => "Official {$room}{$guest}", 'documents' => [['type' => 'passport', 'number' => "P{$room}{$guest}", 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $member->id, 'guest_id' => $guest->id]);
        }

        return app(CheckIn::class)->handle($tenant->owner, $member->fresh());
    });
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

// protects=the paying institution is locked once ZRA has, or may have, the check-in body (spec #242 story 7); fails_when=an accepted, pending or unknown check-in can be edited, or a refused one cannot; no_claim=re-filing.
test('a checked-in stay is editable only while its check-in filing is refused', function (Closure $zra, bool $drain, bool $editable) {
    $t = twoTenants();
    [$first] = institutionGroup($t->a);
    Http::fake([VfmsHttp::TEST_URL.'accommodation/' => $zra]);
    if (! $drain) {
        Queue::fake([DrainOutboxMessage::class]);
    }
    checkInInstitutionMember($t->a, $first, '101');

    $response = correctInstitution($this, $t->a, $first);

    if ($editable) {
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasErrors('paying_institution_type');
    }
    expect(institutionOf($t->a, $first)['paying_institution_zrb_number'])->toBe($editable ? 'Z-RIGHT' : 'Z-WRONG');
})->with([
    'refused' => [fn () => Http::response(['statusCode' => 5000, 'statusMessage' => 'PLEASE USE THE CORRECT ZRB NUMBER']), true, true],
    'accepted' => [fn () => Http::response(['responseNumber' => 'AC-244']), true, false],
    'unknown' => [fn () => Http::response([], 500), true, false],
    'pending' => [fn () => Http::response(['responseNumber' => 'AC-244']), false, false],
]);

// protects=one correction reaches every editable room and names the rooms it left alone (spec #242); fails_when=an accepted room is changed or a locked room goes unreported; no_claim=the toast's wording.
test('a group correction skips rooms whose check-in ZRA accepted and names them', function () {
    $t = twoTenants();
    [$first, $second] = institutionGroup($t->a);
    Http::fake([VfmsHttp::TEST_URL.'accommodation/' => fn (Request $request) => Http::response($request['roomName'] === '101'
        ? ['statusCode' => 5000, 'statusMessage' => 'PLEASE USE THE CORRECT ZRB NUMBER']
        : ['responseNumber' => 'AC-244'])]);
    checkInInstitutionMember($t->a, $first, '101');
    checkInInstitutionMember($t->a, $second, '102');

    correctInstitution($this, $t->a, $first)->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'warning' && str_contains($toast['message'], $second->reservation_number) && ! str_contains($toast['message'], $first->reservation_number));

    expect(institutionOf($t->a, $first)['paying_institution_zrb_number'])->toBe('Z-RIGHT')
        ->and(institutionOf($t->a, $second)['paying_institution_zrb_number'])->toBe('Z-WRONG')
        ->and(inTenant($t->a->property, fn () => $second->lifecycleEvents()->where('type', 'paying_institution_changed')->count()))->toBe(0);
});
