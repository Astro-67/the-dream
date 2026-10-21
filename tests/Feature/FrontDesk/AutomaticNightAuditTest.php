<?php

use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Tests\Support\BookingForm;

test('seeded Zanzibar levy never blocks night audit for an allowed folio currency', function (string $currency) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->update(['business_date' => '2026-12-10', 'jurisdiction_profile' => 'zanzibar']);
    inTenant($property, function () use ($t, $property, $currency) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $property);
        $details = BookingForm::details($t->a->owner);
        RatePlan::query()->whereKey($details['rate_plan_id'])->update(['currency' => $currency]);
        $stay = app(CreateReservation::class)->handle($t->a->owner, $details);
        $stay->update(['status' => ReservationStatus::CheckedIn]);
        app(CloseBusinessDate::class)->handle(null, $property, '2026-12-10');
        $posting = Posting::query()->where('reservation_id', $stay->id)->sole();
        expect($property->fresh()->business_date->toDateString())->toBe('2026-12-11')
            ->and(array_column($posting->tax_lines, 'name'))->toBe($currency === 'USD' ? ['Infrastructure levy', 'VAT'] : ['VAT'])
            ->and($posting->currency->value)->toBe($currency);
    });
})->with(['TZS', 'EUR', 'USD']);

test('the scheduler closes each property whose local close time has passed, as automation', function () {
    $t = twoTenants();
    // 01:30 UTC is 04:30 in Dar es Salaam and 03:30 in London.
    $t->a->property->update(['timezone' => 'Africa/Dar_es_Salaam', 'close_time' => '04:00:00', 'business_date' => '2026-12-10']);
    $t->a->otherProperty->update(['timezone' => 'Europe/London', 'close_time' => '04:00:00', 'business_date' => '2026-12-10']);
    $t->b->property->update(['timezone' => 'Africa/Dar_es_Salaam', 'close_time' => '23:30:00', 'business_date' => '2026-12-10']);
    $t->b->otherProperty->update(['timezone' => 'Africa/Dar_es_Salaam', 'close_time' => '23:30:00', 'business_date' => '2026-12-11']);
    $this->travelTo(new DateTimeImmutable('2026-12-11 01:30:00 UTC'));

    $this->artisan('night-audit:close-due')->assertSuccessful();

    $closed = fn ($property) => inTenant($property, fn () => BusinessDateClose::query()->get(['business_date', 'actor_id', 'completed_at'])->toArray());
    expect(inTenant($t->a->property, fn () => BusinessDateClose::query()->sole()))
        ->business_date->toDateString()->toBe('2026-12-10')
        ->actor_id->toBeNull()
        ->completed_at->not->toBeNull()
        ->and(BusinessDate::of($t->a->property->fresh()))->toBe('2026-12-11')
        ->and(BusinessDate::of($t->b->property->fresh()))->toBe('2026-12-11')
        ->and($closed($t->a->otherProperty))->toBe([])
        ->and($closed($t->b->otherProperty))->toBe([])
        ->and(BusinessDate::of($t->a->otherProperty->fresh()))->toBe('2026-12-10')
        ->and(BusinessDate::of($t->b->otherProperty->fresh()))->toBe('2026-12-11');
});

test('a property that fails to close does not stop the others, and a closed date is not closed again', function () {
    $t = twoTenants();
    foreach ([$t->a->property, $t->b->property] as $property) {
        $property->update(['timezone' => 'Africa/Dar_es_Salaam', 'close_time' => '02:00:00', 'business_date' => '2026-12-10']);
    }
    inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        $reservation->priceLines()->delete();
    });
    $this->travelTo(new DateTimeImmutable('2026-12-11 00:00:00 UTC'));

    $this->artisan('night-audit:close-due')->assertFailed();
    $this->artisan('night-audit:close-due')->assertFailed();

    expect(BusinessDate::of($t->a->property->fresh()))->toBe('2026-12-10')
        ->and(inTenant($t->a->property, fn () => BusinessDateClose::query()->count()))->toBe(0)
        ->and(BusinessDate::of($t->b->property->fresh()))->toBe('2026-12-11')
        ->and(inTenant($t->b->property, fn () => BusinessDateClose::query()->count()))->toBe(1);
});
