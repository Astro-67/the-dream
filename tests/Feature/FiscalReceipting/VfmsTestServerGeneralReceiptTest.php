<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
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
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;
use Tests\TestCase;

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
        'id' => $message->id, 'subject_id' => $message->subject_id,
        'event' => $message->event, 'status' => $message->status, 'references' => $message->getAttribute('provider_references'),
        'mode' => $message->payload['mode'], 'request_type' => $message->payload['request_type'], 'path' => $message->payload['path'],
        'body' => $message->payload['body'],
        'attempts' => $message->attempts->map(fn ($attempt) => ['outcome' => $attempt->outcome, 'response' => (string) $attempt->response_body])->all(),
    ])->all());
    $redacted = str_replace(array_keys($secrets), array_values($secrets), json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents(storage_path("app/private/vfms-test-server-ws-{$name}.json"), $redacted);

    return $calls;
}

function wsTestServerGuard(): void
{
    $testUrl = (string) config('services.vfms.test_url');
    expect(parse_url($testUrl, PHP_URL_HOST))->toBe('102.223.7.131');
    Http::preventStrayRequests();
    Http::allowStrayRequests([rtrim($testUrl, '/').'/*']);
}

function wsTestServerCleanup(TestCase $test, TenantFixture $tenant, array $stays): void
{
    $failures = [];
    foreach ($stays as $stay) {
        try {
            if (inTenant($tenant->property, fn () => $stay->fresh()->status) === ReservationStatus::CheckedIn) {
                $date = inTenant($tenant->property, fn () => $tenant->property->fresh()->business_date);
                if ($date->lessThan($stay->departure)) {
                    $test->actingAs($tenant->owner)->post(route('night-audit.store'), [
                        '_property' => $tenant->property->id, 'business_date' => $date->toDateString(),
                    ])->assertSessionHasNoErrors();
                }
                $test->actingAs($tenant->owner)->post(route('reservations.check-out', $stay), [
                    '_property' => $tenant->property->id, 'override_reason' => 'Test server cleanup after scenario',
                ])->assertSessionHasNoErrors();
            }
            $messages = inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->where('subject_id', $stay->id)->get());
            if ($messages->where('event', 'accommodation')->where('status', 'delivered')->isNotEmpty()
                && $messages->where('event', 'checkout')->where('status', 'delivered')->isEmpty()) {
                $failures[] = $stay->id.': accepted check-in has no accepted check-out';
            }
        } catch (Throwable) {
            $failures[] = $stay->id.': cleanup route failed';
        }
    }
    if ($failures !== []) {
        throw new RuntimeException('ZRA test stay cleanup failed: '.implode('; ', $failures));
    }
}

test('a normal stay gets two guest receipts and a post-checkout general receipt from ZRA\'s test server', function () {
    wsTestServerGuard();
    $t = twoTenants()->a;
    $property = ['_property' => $t->property->id];
    $run = 'WS'.Str::upper(Str::random(6));
    [$stay] = wsTestServerStays($t, $run, 1);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    expect(inTenant($t->property, fn () => VfmsCredential::for($t->property)->mode))->toBe(VfmsMode::Sandbox);

    try {
        $this->actingAs($t->member(OrganizationRole::FrontDesk))->post(route('reservations.check-in', $stay), $property)->assertSessionHasNoErrors();
        $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => 'water-'.$run])->assertSessionHasNoErrors();
        $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'laundry', 'description' => 'LAUNDRY', 'quantity' => 1, 'unit_price' => 1500, 'idempotency_key' => 'laundry-'.$run])->assertSessionHasNoErrors();
        $this->actingAs($t->member(OrganizationRole::PropertyManager))->post(route('night-audit.store'), [...$property, 'business_date' => $t->property->business_date->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($t->owner)->post(route('reservations.check-out', $stay), [...$property, 'override_reason' => 'Test server run settles later'])->assertSessionHasNoErrors();
        $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'restaurant', 'description' => 'LATE DINNER', 'quantity' => 1, 'unit_price' => 2500, 'discount' => 500, 'idempotency_key' => 'dinner-'.$run])->assertSessionHasNoErrors();
    } finally {
        try {
            wsTestServerCleanup($this, $t, [$stay]);
        } finally {
            $calls = wsTestServerLog($t, 'after-checkout', ['P'.$run.'1' => '[DOC-1]']);
        }
    }
    expect(array_column($calls, 'event'))->toBe(['accommodation', 'services', 'services', 'checkout', 'generalReceipt'])
        ->and(array_values(array_unique(array_column($calls, 'status'))))->toBe(['delivered'])
        ->and($calls[4]['body'])->toMatchArray(['documentNumber' => 'Test Guest 1', 'type' => 'WS', 'currency' => 'USD'])
        ->and($calls[4]['body']['service'])->toEqual([['itemName' => 'LATE DINNER', 'price' => 25, 'quantity' => 1, 'discount' => 5]]);
})->skip(getenv('VFMS_TEST_SERVER_RUN') !== '1', 'Real ZRA test-server run: set VFMS_TEST_SERVER_RUN=1.');

// Needs VFMS_TEST_BTOB_ZRB_NUMBER: a ZRB number the test server accepts for BTOB (WITHHOLD is refused there, 10 Nov 2026).
test('a BTOB group gets institution receipts per currency from ZRA\'s test server, then one at the last check-out', function () {
    wsTestServerGuard();
    $zrb = (string) config('services.vfms.test_btob_zrb_number');
    expect($zrb)->not->toBe('');
    $t = twoTenants()->a;
    $property = ['_property' => $t->property->id];
    $run = 'WG'.Str::upper(Str::random(6));
    [$first, $second] = wsTestServerStays($t, $run, 2, ['paying_institution_type' => 'business', 'paying_institution_name' => 'Test Institution '.$run, 'paying_institution_zrb_number' => $zrb]);
    $desk = $t->member(OrganizationRole::FrontDesk);
    expect(inTenant($t->property, fn () => VfmsCredential::for($t->property)->mode))->toBe(VfmsMode::Sandbox);
    try {
        foreach ([$first, $second] as $stay) {
            $this->actingAs($desk)->post(route('reservations.check-in', $stay), $property)->assertSessionHasNoErrors();
        }
        $usd = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $first->id)->sole());
        $this->post(route('reservations.folios.store', $second), [...$property, 'currency' => 'TZS'])->assertSessionHasNoErrors();
        $tzs = inTenant($t->property, fn () => Folio::query()->where('reservation_id', $second->id)->where('currency', 'TZS')->sole());
        $this->post(route('folios.charges.store', $usd), [...$property, 'category' => 'restaurant', 'description' => 'DINNER', 'quantity' => 2, 'unit_price' => 1800, 'discount' => 600, 'idempotency_key' => 'dinner-'.$run])->assertSessionHasNoErrors();
        $this->post(route('folios.charges.store', $tzs), [...$property, 'category' => 'other', 'description' => 'CONFERENCE HALL', 'quantity' => 1, 'unit_price' => 150000, 'idempotency_key' => 'hall-'.$run])->assertSessionHasNoErrors();
        expect(inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->whereIn('event', ['services', 'generalReceipt'])->count()))->toBe(0);
        $this->post(route('reservations.general-receipt.store', $first), $property)->assertSessionHasNoErrors();
        $this->post(route('folios.charges.store', $usd), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 3, 'unit_price' => 500, 'idempotency_key' => 'water-'.$run])->assertSessionHasNoErrors();
        $this->actingAs($t->member(OrganizationRole::PropertyManager))->post(route('night-audit.store'), [...$property, 'business_date' => $t->property->business_date->toDateString()])->assertSessionHasNoErrors();
        foreach ([$first, $second] as $stay) {
            $this->actingAs($t->owner)->post(route('reservations.check-out', $stay), [...$property, 'override_reason' => 'Test server run settles later'])->assertSessionHasNoErrors();
            if ($stay->is($first)) {
                expect(inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->where('event', 'generalReceipt')->count()))->toBe(2);
            }
        }
    } finally {
        try {
            wsTestServerCleanup($this, $t, [$first, $second]);
        } finally {
            $calls = wsTestServerLog($t, 'btob-group', ['P'.$run.'1' => '[DOC-1]', 'P'.$run.'2' => '[DOC-2]', $zrb => '[ZRB]']);
        }
    }
    $receipts = array_values(array_filter($calls, fn (array $call) => $call['event'] === 'generalReceipt'));
    expect(array_count_values(array_column($calls, 'event')))->toEqual(['accommodation' => 2, 'generalReceipt' => 3, 'checkout' => 2])
        ->and(array_values(array_unique(array_column($calls, 'status'))))->toBe(['delivered'])
        ->and(array_map(fn (array $call) => [$call['body']['currency'], array_column($call['body']['service'], 'itemName')], $receipts))
        ->toEqual([['USD', ['DINNER']], ['TZS', ['CONFERENCE HALL']], ['USD', ['WATER']]])
        ->and(array_values(array_unique(array_map(fn (array $call) => $call['body']['documentNumber'], $receipts))))->toBe(['Test Institution '.$run]);
})->skip(fn () => getenv('VFMS_TEST_SERVER_RUN') !== '1' || blank(config('services.vfms.test_btob_zrb_number')), 'Real ZRA test-server run: set VFMS_TEST_SERVER_RUN=1 and VFMS_TEST_BTOB_ZRB_NUMBER.');

test('test-server stay cleanup attempts every room and reports a refused checkout after a scenario fails', function (bool $refuseFirst) {
    Http::fake([VfmsHttp::TEST_URL.'guest/checkout/' => function (Request $request) use ($refuseFirst) {
        if ($refuseFirst && str_ends_with($request['documentNumberList'][0]['documentNumber'], '1')) {
            return Http::response(['statusCode' => 5000, 'message' => 'Test cleanup refusal']);
        }
        $report = json_decode(file_get_contents(base_path('tests/Fixtures/vfms/checkout.json')), true, flags: JSON_THROW_ON_ERROR)[0];
        $report['guest']['documentNo'] = $request['documentNumberList'][0]['documentNumber'];

        return Http::response([$report]);
    }]);
    VfmsHttp::fake();
    $tenant = twoTenants()->a;
    $stays = wsTestServerStays($tenant, 'CLEANUP', 2);
    try {
        foreach ($stays as $stay) {
            $this->actingAs($tenant->owner)->post(route('reservations.check-in', $stay), ['_property' => $tenant->property->id])->assertSessionHasNoErrors();
        }
        throw new RuntimeException('Scenario failed after accepted check-ins');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Scenario failed after accepted check-ins');
    } finally {
        if ($refuseFirst) {
            expect(fn () => wsTestServerCleanup($this, $tenant, $stays))->toThrow(RuntimeException::class, $stays[0]->id.': accepted check-in has no accepted check-out');
        } else {
            wsTestServerCleanup($this, $tenant, $stays);
        }
    }

    $messages = inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->get());
    $checkouts = $messages->where('event', 'checkout');
    expect($checkouts->firstWhere('subject_id', $stays[0]->id)->status)->toBe($refuseFirst ? 'rejected' : 'delivered')
        ->and($checkouts->firstWhere('subject_id', $stays[1]->id)->status)->toBe('delivered');
})->with(['accepted cleanup' => false, 'refused first room' => true]);

test('the real general-receipt driver refuses any host except the authorized test server', function () {
    config(['services.vfms.test_url' => 'https://not-the-test-server.example/api/']);

    expect(fn () => wsTestServerGuard())->toThrow(AssertionFailedError::class);
});
