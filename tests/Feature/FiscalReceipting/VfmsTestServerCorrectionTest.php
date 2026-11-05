<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
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
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\OutboxCorrectionContext;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InventoryForm;

// Real calls to ZRA's shared TEST server, never Live. Opt in with VFMS_TEST_SERVER_RUN=1 and the VFMS_TEST_* values
// in .env; writes a redacted call log to storage/app/private/vfms-test-server-correction.json.
test('a Sandbox correction pair is accepted by ZRA\'s test server', function () {
    $testUrl = (string) config('services.vfms.test_url');
    $origin = parse_url($testUrl);
    expect($testUrl)->not->toBe('')->and(strtolower($testUrl))->not->toContain('zanrevenue.org');
    $gateway = $origin['scheme'].'://'.$origin['host'].(isset($origin['port']) ? ':'.$origin['port'] : '');
    // Only the test gateway's filing API and error-management routes may be reached; anything else (Live included) throws.
    Http::allowStrayRequests([rtrim($testUrl, '/').'/*', $gateway.'/vfms/error-management/*']);

    $t = twoTenants()->a;
    $property = ['_property' => $t->property->id];
    $run = Str::upper(Str::random(6));
    $document = 'P'.$run.'1';
    $today = $t->property->business_date->toDateString();
    $reservation = inTenant($t->property, function () use ($t, $document, $today) {
        $t->property->update(['email' => 'test@example.com', 'phone' => '0777000000', 'z_number' => 'Z000000000']);
        $type = app(CreateRoomType::class)->handle($t->owner, InventoryForm::roomType());
        app(CreateRoom::class)->handle($t->owner, InventoryForm::room($type->id, ['number' => '7']));
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $plan = app(CreateRatePlan::class)->handle($t->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'USD', 'room_type_ids' => [$type->id]]);
        app(SetRatePrices::class)->handle($t->owner, $plan, ['room_type_id' => $type->id, 'from' => $today, 'to' => $today, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 10000, 'extra_adult' => 2500, 'extra_child' => 1000]);
        $booker = app(CreateBooker::class)->handle($t->owner, ['name' => 'Test Booker']);
        $reservation = app(CreateReservation::class)->handle($t->owner, [
            'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'booker_id' => $booker->id, 'room_id' => Room::query()->where('number', '7')->sole()->id,
            'arrival' => $today, 'departure' => $t->property->business_date->addDay()->toDateString(), 'adults' => 1, 'children' => 0, 'source' => 'phone', 'meal_plan' => 'bed-and-breakfast',
        ]);
        $guest = app(CreateGuest::class)->handle($t->owner, ['name' => 'Test Anna', 'documents' => [['type' => 'passport', 'number' => $document, 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return $reservation;
    });
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $desk = $t->member(OrganizationRole::FrontDesk);

    $this->actingAs($desk)->post(route('reservations.check-in', $reservation), $property)->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => 'water-a-'.$run])->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $folio), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 1, 'unit_price' => 1000, 'idempotency_key' => 'water-b-'.$run])->assertSessionHasNoErrors();
    [$old, $new] = inTenant($t->property, function () {
        [$old, $new] = IssuedDocument::query()->where('type', 'services_receipt')->orderBy('issued_at')->orderBy('id')->get()->all();
        // shortcut: Karafuu cannot issue a corrected receipt for the same service (spec #228 forbids replaying a filing),
        // so the second real receipt stands in as the first service's correction; drop once ZRA defines re-issuance.
        $message = OutboxMessage::query()->findOrFail($new->outbox_message_id);
        app(OutboxCorrectionContext::class)->run(fn () => $message->update(['payload' => [...$message->payload, 'posting_id' => $old->posting_id]]));
        DB::table('issued_documents')->where('id', $new->id)->update(['posting_id' => $old->posting_id]);

        return [$old, $new->fresh()];
    });
    $this->actingAs($t->owner)->post(route('issued-documents.corrections.store', $old), [
        ...$property, 'replacement_id' => $new->id, 'reason' => 'Karafuu integration test: wrong quantity', 'confirmed' => true,
    ])->assertSessionHasNoErrors();
    $this->actingAs($t->member(OrganizationRole::PropertyManager))->post(route('night-audit.store'), [...$property, 'business_date' => $today])->assertSessionHasNoErrors();
    $this->actingAs($t->owner)->post(route('reservations.check-out', $reservation), [...$property, 'override_reason' => 'Test server run settles later'])->assertSessionHasNoErrors();

    $messages = inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->with('attempts')->orderBy('sequence')->get());
    $log = ['run' => $run, 'calls' => $messages->map(fn (OutboxMessage $message) => [
        'event' => $message->event, 'status' => $message->status, 'references' => $message->getAttribute('provider_references'),
        'attempts' => $message->attempts->map(fn ($attempt) => ['outcome' => $attempt->outcome, 'response' => str_replace($document, '[DOC-1]', (string) $attempt->response_body)])->all(),
    ])->all()];
    file_put_contents(storage_path('app/private/vfms-test-server-correction.json'), json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    expect($messages->pluck('event')->all())->toBe(['accommodation', 'services', 'services', 'errorSave', 'checkout'])
        ->and($messages->pluck('status')->unique()->all())->toBe(['delivered']);
    $pair = DB::table('document_corrections')->sole();
    expect($pair->mode)->toBe('sandbox')->and($pair->original_number)->toBe($old->number)->and($pair->replacement_number)->toBe($new->number);
})->skip(getenv('VFMS_TEST_SERVER_RUN') !== '1', 'Real ZRA test-server run: set VFMS_TEST_SERVER_RUN=1.');
