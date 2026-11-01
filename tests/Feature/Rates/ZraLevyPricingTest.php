<?php

use App\Domains\FiscalReceipting\Actions\FetchExchangeRates;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Support\Money\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryForm;

const ZRA_RATE_URL = 'https://vfms-live.example/api/';

/** Fakes ZRA's Live exchange-rate reply and gives property A Live credentials. */
function zraLiveRates(mixed $reply): void
{
    config(['services.vfms.live_url' => ZRA_RATE_URL]);
    Http::fake([ZRA_RATE_URL.'currencyExchangeRate/' => $reply]);
}

// protects=the daily fetch stores ZRA's rate once per date through a Live property's credentials; fails_when=it uses Sandbox credentials, refetches a stored day, or stores a partial reply.
test('the exchange-rate fetch stores ZRA rates once a day from a Live property', function () {
    $t = twoTenants();
    zraLiveRates(Http::response(['rate_id' => null, 'rateUSD' => 2555, 'rateEURO' => 2581.175, 'rateUSDEURO' => 1.010244]));
    inTenant($t->a->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT', 'registration_id' => 'REG', 'token' => 'TOKEN']));

    // Sandbox credentials are never used.
    expect(app(FetchExchangeRates::class)->handle('2026-11-01'))->toBeNull();
    Http::assertNothingSent();

    inTenant($t->a->property, fn () => VfmsCredential::query()->sole()->update(['mode' => 'live', 'live_since' => now()]));
    $rate = app(FetchExchangeRates::class)->handle('2026-11-01');
    app(FetchExchangeRates::class)->handle('2026-11-01');

    expect($rate)->usd_tzs->toBe('2555.000000')->eur_tzs->toBe('2581.175000')
        ->and(ExchangeRate::query()->count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('vfms-request-type', 'CURRENCY_EXCHANGE') && $request->hasHeader('vfms-token-id', 'TOKEN'));
    // A later day without a rate uses the latest stored one.
    expect(ExchangeRate::forDate('2026-11-05')?->usd_tzs)->toBe('2555.000000');
});

test('a ZRA reply without every rate stores nothing', function () {
    $t = twoTenants();
    zraLiveRates(Http::response(['rateUSD' => 2555]));
    inTenant($t->a->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT', 'registration_id' => 'REG', 'token' => 'TOKEN', 'mode' => 'live', 'live_since' => now()]));

    expect(app(FetchExchangeRates::class)->handle('2026-11-01'))->toBeNull()->and(ExchangeRate::query()->count())->toBe(0);
});

// protects=the price screen shows what a guest pays with the levy at ZRA's rate on top; fails_when=a TZS plan shows the levy as unavailable once a rate exists.
test('a TZS rate plan shows the guest price with VAT inside and the converted levy on top', function () {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-11-01', 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]);
    ExchangeRate::query()->create(['date' => '2026-11-01', 'usd_tzs' => 2555, 'eur_tzs' => 2581.175]);
    $plan = inTenant($t->a->property, function () use ($t) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $t->a->property);
        $room = app(CreateRoomType::class)->handle($t->a->owner, InventoryForm::roomType(['base_occupancy' => 2]));
        $plan = app(CreateRatePlan::class)->handle($t->a->owner, ['name' => 'Breakfast', 'code' => 'BB', 'currency' => 'TZS', 'room_type_ids' => [$room->id]]);
        app(SetRatePrices::class)->handle($t->a->owner, $plan, ['room_type_id' => $room->id, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'from' => '2026-11-01', 'to' => '2026-11-01', 'base_price' => 220000, 'extra_adult' => 0, 'extra_child' => 0]);

        return $plan;
    });

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('rate-plans.prices', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page
        // Two guests at base occupancy: 220,000 + 2 x 12,775.
        ->where('prices.0.guest_pays', 245550)
        ->where('taxes.0.name', 'Infrastructure levy')->where('taxes.0.inclusive', false)->where('taxes.0.per_person_night', 12775)
        ->where('taxes.0.converted_from.rate_date', '2026-11-01')
        ->where('taxes.1.name', 'VAT')->where('taxes.1.inclusive', true));
});
