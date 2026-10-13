<?php

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;

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
