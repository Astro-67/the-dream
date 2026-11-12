<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

// Real calls to ZRA's shared TEST server (spec #249, ticket #250), never Live. Opt in with VFMS_TEST_SERVER_RUN=1 and the
// VFMS_TEST_* values in .env. Writes a redacted call log per scenario to storage/app/private/vfms-test-server-ws-*.json.

/** Sets up $rooms rooms with one fake-passport guest each, booked tonight in USD; returns the reservations. */
function wsTestServerStays(TenantFixture $t, string $run, int $rooms, array $details = []): array
{
    return inTenant($t->property, function () use ($t, $run, $rooms, $details) {
        $type = app(CreateRoomType::class)->handle($t->owner, InventoryForm::roomType());
        foreach (range(1, $rooms) as $index) {
            app(CreateRoom::class)->handle($t->owner, InventoryForm::room($type->id, ['number' => (string) (6 + $index)]));
        }
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $today = $t->property->business_date->toDateString();
        $plan = app(CreateRatePlan::class)->handle($t->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$type->id]]);
        app(SetRatePrices::class)->handle($t->owner, $plan, ['room_type_id' => $type->id, 'from' => $today, 'to' => $today, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);
        $booker = app(CreateBooker::class)->handle($t->owner, ['name' => 'Test Booker']);
        app(CreateReservation::class)->handle($t->owner, [
            'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'booker_id' => $booker->id,
            'arrival' => $today, 'departure' => $t->property->business_date->addDay()->toDateString(),
            'rooms' => $rooms, 'adults' => $rooms, 'children' => 0, 'distribute_guests' => true, 'source' => 'phone', 'meal_plan' => 'bed-and-breakfast', ...$details,
        ]);
        $stays = Reservation::query()->orderBy('reservation_number')->get();
        $roomRows = Room::query()->orderBy('number')->get();
        foreach ($stays as $index => $stay) {
            $guest = app(CreateGuest::class)->handle($t->owner, ['name' => 'Test Guest '.($index + 1), 'documents' => [['type' => 'passport', 'number' => 'P'.$run.($index + 1), 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $stay->id, 'guest_id' => $guest->id]);
            $stay->update(['room_id' => $roomRows[$index]->id]);
        }

        return $stays->all();
    });
}

/** @return list<array<string, mixed>> */
function wsTestServerLog(TenantFixture $t, string $name, array $secrets): array
{
    $calls = inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->with('attempts')->orderBy('created_at')->orderBy('sequence')->get()->map(fn (OutboxMessage $message) => [
        'event' => $message->event, 'status' => $message->status, 'references' => $message->getAttribute('provider_references'),
        'body' => $message->event === 'generalReceipt' ? $message->payload['body'] : null,
        'attempts' => $message->attempts->map(fn ($attempt) => ['outcome' => $attempt->outcome, 'response' => (string) $attempt->response_body])->all(),
    ])->all());
    $redacted = str_replace(array_keys($secrets), array_values($secrets), json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents(storage_path("app/private/vfms-test-server-ws-{$name}.json"), $redacted);

    return $calls;
}

function wsTestServerGuard(): void
{
    $testUrl = (string) config('services.vfms.test_url');
    expect($testUrl)->not->toBe('')->and(strtolower($testUrl))->not->toContain('zanrevenue.org');
    Http::allowStrayRequests([rtrim($testUrl, '/').'/*']);
}

test('an extra after check-out gets a general receipt from ZRA\'s test server', function () {
    wsTestServerGuard();
    $t = twoTenants()->a;
    $property = ['_property' => $t->property->id];
    $run = 'WS'.Str::upper(Str::random(6));
    [$stay] = wsTestServerStays($t, $run, 1);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());

    $this->actingAs($t->member(OrganizationRole::FrontDesk))->post(route('reservations.check-in', $stay), $property)->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => 'water-'.$run])->assertSessionHasNoErrors();
    $this->actingAs($t->member(OrganizationRole::PropertyManager))->post(route('night-audit.store'), [...$property, 'business_date' => $t->property->business_date->toDateString()])->assertSessionHasNoErrors();
    $this->actingAs($t->owner)->post(route('reservations.check-out', $stay), [...$property, 'override_reason' => 'Test server run settles later'])->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'restaurant', 'description' => 'LATE DINNER', 'quantity' => 1, 'unit_price' => 2500, 'discount' => 500, 'idempotency_key' => 'dinner-'.$run])->assertSessionHasNoErrors();

    $calls = wsTestServerLog($t, 'after-checkout', ['P'.$run.'1' => '[DOC-1]']);
    expect(array_column($calls, 'event'))->toBe(['accommodation', 'services', 'checkout', 'generalReceipt'])
        ->and(array_values(array_unique(array_column($calls, 'status'))))->toBe(['delivered'])
        ->and($calls[3]['body'])->toMatchArray(['documentNumber' => 'Test Guest 1', 'type' => 'WS', 'currency' => 'USD'])
        ->and($calls[3]['body']['service'])->toEqual([['itemName' => 'LATE DINNER', 'price' => 25, 'quantity' => 1, 'discount' => 5]]);
})->skip(getenv('VFMS_TEST_SERVER_RUN') !== '1', 'Real ZRA test-server run: set VFMS_TEST_SERVER_RUN=1.');

// Needs VFMS_TEST_BTOB_ZRB_NUMBER: a ZRB number the test server accepts for BTOB (WITHHOLD is refused there, 10 Nov 2026).
test('a BTOB group gets institution receipts per currency from ZRA\'s test server, then one at the last check-out', function () {
    wsTestServerGuard();
    $zrb = (string) config('services.vfms.test_btob_zrb_number');
    $t = twoTenants()->a;
    $property = ['_property' => $t->property->id];
    $run = 'WG'.Str::upper(Str::random(6));
    [$first, $second] = wsTestServerStays($t, $run, 2, ['paying_institution_type' => 'business', 'paying_institution_name' => 'Test Institution '.$run, 'paying_institution_zrb_number' => $zrb]);
    $desk = $t->member(OrganizationRole::FrontDesk);
    foreach ([$first, $second] as $stay) {
        $this->actingAs($desk)->post(route('reservations.check-in', $stay), $property)->assertSessionHasNoErrors();
    }
    $usd = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
    $this->post(route('reservations.folios.store', $second), [...$property, 'currency' => 'TZS'])->assertSessionHasNoErrors();
    $tzs = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $second->id)->where('currency', 'TZS')->sole());
    $this->post(route('folios.charges.store', $usd), [...$property, 'category' => 'restaurant', 'description' => 'DINNER', 'quantity' => 2, 'unit_price' => 1800, 'discount' => 600, 'idempotency_key' => 'dinner-'.$run])->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $tzs), [...$property, 'category' => 'other', 'description' => 'CONFERENCE HALL', 'quantity' => 1, 'unit_price' => 150000, 'idempotency_key' => 'hall-'.$run])->assertSessionHasNoErrors();
    $this->post(route('reservations.general-receipt.store', $first), $property)->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $usd), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 3, 'unit_price' => 500, 'idempotency_key' => 'water-'.$run])->assertSessionHasNoErrors();
    $this->actingAs($t->member(OrganizationRole::PropertyManager))->post(route('night-audit.store'), [...$property, 'business_date' => $t->property->business_date->toDateString()])->assertSessionHasNoErrors();
    foreach ([$first, $second] as $stay) {
        $this->actingAs($t->owner)->post(route('reservations.check-out', $stay), [...$property, 'override_reason' => 'Test server run settles later'])->assertSessionHasNoErrors();
    }

    $calls = wsTestServerLog($t, 'btob-group', ['P'.$run.'1' => '[DOC-1]', 'P'.$run.'2' => '[DOC-2]', $zrb => '[ZRB]']);
    $receipts = array_values(array_filter($calls, fn (array $call) => $call['event'] === 'generalReceipt'));
    expect(array_count_values(array_column($calls, 'event')))->toEqual(['accommodation' => 2, 'generalReceipt' => 3, 'checkout' => 2])
        ->and(array_values(array_unique(array_column($calls, 'status'))))->toBe(['delivered'])
        ->and(array_map(fn (array $call) => [$call['body']['currency'], array_column($call['body']['service'], 'itemName')], $receipts))
        ->toEqual([['USD', ['DINNER']], ['TZS', ['CONFERENCE HALL']], ['USD', ['WATER']]])
        ->and(array_values(array_unique(array_map(fn (array $call) => $call['body']['documentNumber'], $receipts))))->toBe(['Test Institution '.$run]);
})->skip(getenv('VFMS_TEST_SERVER_RUN') !== '1' || blank(getenv('VFMS_TEST_BTOB_ZRB_NUMBER')), 'Real ZRA test-server run: set VFMS_TEST_SERVER_RUN=1 and VFMS_TEST_BTOB_ZRB_NUMBER.');
