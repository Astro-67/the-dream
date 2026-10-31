<?php

use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TenantFixture;

const ZRA_LIVE_URL = 'https://vfms-live.example/api/';

/** Fakes ZRA's Live exchange-rate endpoint with $reply and saves Alpha's own credentials (Sandbox mode). */
function zraCheckSetup(TenantFixture $tenant, mixed $reply): void
{
    config(['services.vfms.live_url' => ZRA_LIVE_URL]);
    Http::fake([ZRA_LIVE_URL.'currencyExchangeRate/' => $reply]);
    inTenant($tenant->property, fn () => VfmsCredential::query()->create(['integration_id' => 'OWN-INT', 'registration_id' => 'OWN-REG', 'token' => 'OWN-TOKEN']));
}

function checkWithZra(TenantFixture $tenant)
{
    return test()->actingAs($tenant->owner)->post(route('properties.vfms.check', $tenant->property), ['_property' => $tenant->property->id]);
}

/** Shaped like ZRA's documented reply (production guide v1.6, 2.4). */
const ZRA_RATES = ['rate_id' => null, 'rateUSD' => 3205.0, 'rateEURO' => 2721.23, 'rateUSDEURO' => 1.17];

// protects=the check is ZRA's read-only exchange-rate call with the property's own credentials (#199); fails_when=it uses another request, shared test credentials, or queues a filing; no_claim=ZRA's real behaviour.
test('Check with ZRA sends the exchange-rate request with the property credentials and stores accepted with its time', function () {
    $t = twoTenants();
    zraCheckSetup($t->a, Http::response(ZRA_RATES));
    $this->freezeTime();

    checkWithZra($t->a)->assertSessionHasNoErrors()->assertRedirect();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === ZRA_LIVE_URL.'currencyExchangeRate/'
        && $request->method() === 'POST'
        && $request->header('vfms-request-type') === ['CURRENCY_EXCHANGE']
        && $request->header('vfms-intergration-id') === ['OWN-INT']
        && $request->header('vfms-token-id') === ['OWN-TOKEN']);
    expect(inTenant($t->a->organization, fn () => OutboxMessage::query()->count()))->toBe(0);
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page
        ->where('vfms.zra_check', 'accepted')
        ->where('vfms.zra_checked_at', now()->toIso8601String()));
});

// protects=the three outcomes and the refused marker (#199); fails_when=a refusal reads as unreachable, a timeout or odd reply as refused, or a refusal leaves no marker; no_claim=ZRA's real refusal codes.
test('ZRA replies map to refused or unreachable, and refused sets the refused marker', function (Closure $reply, string $outcome) {
    $t = twoTenants();
    zraCheckSetup($t->a, $reply);

    checkWithZra($t->a)->assertSessionHasNoErrors();

    $credential = VfmsCredential::for($t->a->property);
    expect($credential->zra_check)->toBe($outcome)
        ->and($credential->credentials_refused_at !== null)->toBe($outcome === 'refused');
})->with([
    '401' => [fn () => Http::response(['error' => 'Unauthorized'], 401), 'refused'],
    '403' => [fn () => Http::response('', 403), 'refused'],
    'refusal status' => [fn () => Http::response(['statusCode' => 5000, 'statusMessage' => 'Invalid token']), 'refused'],
    'timeout' => [fn () => Http::failedConnection(), 'unreachable'],
    'server error' => [fn () => Http::response('', 503), 'unreachable'],
    'no rates' => [fn () => Http::response(['rate_id' => null]), 'unreachable'],
]);

// protects=saving new credentials replaces the previous outcome with a fresh check (#199); fails_when=an accepted outcome survives a save that ZRA then refuses; no_claim=the go-live gate.
test('saving credentials clears the previous outcome and checks the new ones with ZRA', function () {
    $t = twoTenants();
    zraCheckSetup($t->a, Http::sequence()->push(ZRA_RATES)->push([], 401));
    checkWithZra($t->a);
    expect(VfmsCredential::for($t->a->property)->zra_check)->toBe('accepted');
    $this->travel(61)->seconds();

    $this->put(route('properties.vfms.update', $t->a->property), ['_property' => $t->a->property->id, 'integration_id' => 'TYPO-INT', 'registration_id' => 'OWN-REG', 'token' => 'NEW-TOKEN'])
        ->assertSessionHasNoErrors();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->header('vfms-intergration-id') === ['TYPO-INT']);
    expect(VfmsCredential::for($t->a->property)->zra_check)->toBe('refused');
});

// protects=a save within the minute never keeps an outcome for other credentials (#199); fails_when=a throttled save keeps the old accepted outcome or calls ZRA again; no_claim=the button's throttle message.
test('a save within a minute of a check clears the outcome without calling ZRA', function () {
    $t = twoTenants();
    zraCheckSetup($t->a, Http::response(ZRA_RATES));
    checkWithZra($t->a);

    $this->put(route('properties.vfms.update', $t->a->property), ['_property' => $t->a->property->id, 'integration_id' => 'OTHER-INT', 'registration_id' => 'OWN-REG', 'token' => ''])
        ->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    expect(VfmsCredential::for($t->a->property))->zra_check->toBeNull()->zra_checked_at->toBeNull();
});

// protects=at most one check per property per minute (#199); fails_when=a second press within the minute reaches ZRA, or the limit spills to another property; no_claim=the limiter store.
test('Check with ZRA runs at most once per property per minute', function () {
    $t = twoTenants();
    zraCheckSetup($t->a, Http::response(ZRA_RATES));
    inTenant($t->b->property, fn () => VfmsCredential::query()->create(['integration_id' => 'B-INT', 'registration_id' => 'B-REG', 'token' => 'B-TOKEN']));

    checkWithZra($t->a)->assertSessionHasNoErrors();
    checkWithZra($t->a)->assertSessionHasErrors('zra_check');
    checkWithZra($t->b)->assertSessionHasNoErrors();
    $this->travel(61)->seconds();
    checkWithZra($t->a)->assertSessionHasNoErrors();

    Http::assertSentCount(3);
});

// protects=every check is audited and the token stays secret (#199); fails_when=a check leaves no audit entry, or the token reaches the audit, a log line, the flash or the page; no_claim=HTTP headers, which must carry it.
test('every check is audited with its outcome and never the token', function () {
    $t = twoTenants();
    zraCheckSetup($t->a, Http::response([], 401));
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
        $logged[] = $log->message.json_encode($log->context);
    });

    checkWithZra($t->a)->assertSessionHasNoErrors();
    $flashed = json_encode(session()->all());
    checkWithZra($t->a)->assertSessionHasErrors('zra_check');
    $page = $this->get(route('properties.edit', $t->a->property))->assertOk();

    $audit = Activity::query()->where('event', 'vfms_credentials_checked')->sole();
    expect($audit->causer_id)->toBe($t->a->owner->id)
        ->and($audit->getExtraProperty('attributes')['zra_check'])->toBe('refused')
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $t->a->property->id)->count())->toBe(1)
        ->and(Activity::query()->get()->toJson())->not->toContain('OWN-TOKEN')
        ->and(implode("\n", $logged))->not->toContain('OWN-TOKEN')
        ->and($flashed)->not->toContain('OWN-TOKEN')
        ->and($page->getContent())->not->toContain('OWN-TOKEN');
});
