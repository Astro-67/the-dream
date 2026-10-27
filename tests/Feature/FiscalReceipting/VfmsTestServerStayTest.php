<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Money\Currency;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InventoryForm;

// Real calls to ZRA's shared TEST server (#175 step 2), never Live. Opt in with VFMS_TEST_SERVER_RUN=1 and the
// VFMS_TEST_* values in .env; writes a redacted call log to storage/app/private/vfms-test-server-run.json.
test('a Sandbox stay is accepted end to end by ZRA\'s test server', function () {
    $testUrl = (string) config('services.vfms.test_url');
    $viewer = rtrim((string) config('services.vfms.test_viewer_url'), '/');
    expect($testUrl)->not->toBe('')->and($viewer)->not->toBe('')
        ->and($testUrl)->not->toContain('zanrevenue.org');
    // Only the test gateway and test viewer may be reached; anything else (Live included) throws.
    Http::allowStrayRequests([rtrim($testUrl, '/').'/*', $viewer.'/*']);

    $t = twoTenants()->a;
    $property = ['_property' => $t->property->id];
    $run = Str::upper(Str::random(6));
    $documents = ['P'.$run.'1', 'P'.$run.'2', 'P'.$run.'3'];
    $today = $t->property->business_date->toDateString();
    $tomorrow = $t->property->business_date->addDay()->toDateString();
    $reservation = inTenant($t->property, function () use ($t, $documents, $today, $tomorrow) {
        $type = app(CreateRoomType::class)->handle($t->owner, InventoryForm::roomType());
        foreach (['7', '8'] as $number) {
            app(CreateRoom::class)->handle($t->owner, InventoryForm::room($type->id, ['number' => $number]));
        }
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $plan = app(CreateRatePlan::class)->handle($t->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$type->id]]);
        app(SetRatePrices::class)->handle($t->owner, $plan, ['room_type_id' => $type->id, 'from' => $today, 'to' => $tomorrow, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);
        $booker = app(CreateBooker::class)->handle($t->owner, ['name' => 'Test Booker']);
        $reservation = app(CreateReservation::class)->handle($t->owner, [
            'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'booker_id' => $booker->id, 'room_id' => Room::query()->where('number', '7')->sole()->id,
            'arrival' => $today, 'departure' => CarbonImmutable::parse($tomorrow)->addDay()->toDateString(), 'adults' => 2, 'children' => 0, 'source' => 'phone', 'meal_plan' => 'bed-and-breakfast',
        ]);
        foreach ([['Test Anna', $documents[0], 'DE'], ['Test Ola', $documents[1], 'NO']] as [$name, $number, $country]) {
            $guest = app(CreateGuest::class)->handle($t->owner, ['name' => $name, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => $country]]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        }
        // The second night is dearer, so closing the first night files a price change.
        $reservation->priceLines()->whereDate('date', $tomorrow)->update(['amount' => 12500]);

        return $reservation;
    });
    [$room8, $folio, $ola] = inTenant($t->property, fn () => [Room::query()->where('number', '8')->sole(), Folio::query()->sole(), Guest::query()->where('name', 'Test Ola')->sole()]);
    $desk = $t->member(OrganizationRole::FrontDesk);
    $manager = $t->member(OrganizationRole::PropertyManager);

    $this->actingAs($desk)->post(route('reservations.check-in', $reservation), $property)->assertSessionHasNoErrors();
    $this->post(route('reservations.room-move', $reservation), [...$property, 'room_id' => $room8->id])->assertSessionHasNoErrors();
    $this->post(route('reservations.add-guest', $reservation), [...$property, 'name' => 'Test Carl', 'documents' => [['type' => 'passport', 'number' => $documents[2], 'nationality' => 'IT']]])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post(route('night-audit.store'), [...$property, 'business_date' => $today])->assertSessionHasNoErrors();
    $this->actingAs($desk)->post(route('folios.charges.store', $folio), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => 'water-'.$run])->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'restaurant', 'description' => 'LUNCH', 'quantity' => 1, 'unit_price' => 1500, 'idempotency_key' => 'lunch-'.$run])->assertSessionHasNoErrors();
    $this->post(route('reservations.guest-leaves', $reservation), [...$property, 'guest_id' => $ola->id])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post(route('night-audit.store'), [...$property, 'business_date' => $tomorrow])->assertSessionHasNoErrors();
    $this->actingAs($t->owner)->post(route('reservations.check-out', $reservation), [...$property, 'override_reason' => 'Test server run settles later'])->assertSessionHasNoErrors();

    $messages = inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->with('attempts')->orderBy('sequence')->get());
    $redact = fn (string $text) => str_replace($documents, ['[DOC-1]', '[DOC-2]', '[DOC-3]'], $text);
    $calls = $messages->map(fn (OutboxMessage $message) => [
        'event' => $message->event,
        'status' => $message->status,
        'references' => $message->getAttribute('provider_references'),
        'attempts' => $message->attempts->map(fn ($attempt) => ['outcome' => $attempt->outcome, 'response' => $redact((string) $attempt->response_body)])->all(),
    ])->all();
    $report = $this->actingAs($t->member(OrganizationRole::Finance))->get(route('reservations.fiscal-report', $reservation))->assertOk()->viewData('page')['props'];
    $viewerChecks = collect($report['guestReports'])->mapWithKeys(fn (array $guest) => [$guest['portalUrl'] => Http::get($guest['portalUrl'])->status()])->all();
    $adjustment = inTenant($t->property, fn () => Posting::query()->where('category', 'vfms_adjustment')->first()?->only(['reference', 'amount', 'net', 'tax_lines']));
    $totals = $this->get(route('reservations.show', $reservation))->viewData('page')['props']['totals'] ?? null;
    $log = ['run' => $run, 'calls' => $calls, 'report' => array_diff_key($report, array_flip(['errors', 'auth', 'tenant', 'flash'])), 'viewer' => $viewerChecks, 'adjustment' => $adjustment, 'totals' => $totals];
    file_put_contents(storage_path('app/private/vfms-test-server-run.json'), $redact(json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));

    expect($messages->pluck('event')->all())->toBe(['accommodation', 'changeRoom', 'addGuest', 'changeRoomPrice', 'services', 'services', 'guestDeparture', 'checkout'])
        ->and($messages->pluck('status')->unique()->all())->toBe(['delivered']);
    $checkout = $messages->last()->getAttribute('provider_references');
    expect(json_decode($checkout['reportNumbers'], true))->toHaveCount(3)
        ->and($report['status'])->toBe('sandbox')
        ->and($report['guestReports'])->toHaveCount(3)
        ->and(collect($report['guestReports'])->every(fn (array $guest) => str_starts_with((string) $guest['portalUrl'], $viewer.'/checkout-report/guest/') && filled($guest['qrSvg'])))->toBeTrue()
        ->and(array_values(array_unique($viewerChecks)))->toBe([200])
        ->and($adjustment['reference'])->toBe($checkout['reportNo'])
        ->and($totals[0]['charges'])->toBe(app(VfmsPayloads::class)->minor((float) $checkout['totalAmount'], Currency::USD));
})->skip(getenv('VFMS_TEST_SERVER_RUN') !== '1', 'Real ZRA test-server run: set VFMS_TEST_SERVER_RUN=1.');
