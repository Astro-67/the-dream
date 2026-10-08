<?php

use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\QuotaLimiter;
use Illuminate\Support\Facades\DB;

test('a provider quota identity shares allowance across properties', function () {
    [, , $propertyA] = organizationWithOwner(['organization_name' => 'Alpha']);
    [, , $propertyB] = organizationWithOwner(['organization_name' => 'Bravo']);
    $policy = new DeliveryPolicy(quotaIdentity: 'zan-global', quotaLimit: 2, quotaWindowSeconds: 60);

    $allowedA = inTenant($propertyA, fn () => app(QuotaLimiter::class)->acquire($policy));
    $allowedB = inTenant($propertyB, fn () => app(QuotaLimiter::class)->acquire($policy));
    $deniedA = inTenant($propertyA, fn () => app(QuotaLimiter::class)->acquire($policy));

    expect([$allowedA, $allowedB, $deniedA])->toBe([true, true, false])
        ->and(DB::table('outbox_quotas')->where('identity_hash', hash('sha256', 'zan-global'))->value('used'))->toBe(2);
});

test('a batch consumes quota by provider request count, not tenant count', function () {
    [, , $property] = organizationWithOwner();
    $policy = new DeliveryPolicy(quotaIdentity: 'zan-global', quotaLimit: 3, quotaWindowSeconds: 60);

    expect(app(QuotaLimiter::class)->acquire($policy, 2))->toBeTrue()
        ->and(app(QuotaLimiter::class)->acquire($policy, 2))->toBeFalse()
        ->and(DB::table('outbox_quotas')->where('identity_hash', hash('sha256', 'zan-global'))->value('used'))->toBe(2);
});

test('delivery policies reject mismatched quota identity and limit', function (array $options) {
    expect(fn () => new DeliveryPolicy(...$options))->toThrow(InvalidArgumentException::class);
})->with([
    'identity without limit' => [['quotaIdentity' => 'zan-global']],
    'limit without identity' => [['quotaLimit' => 10]],
    'nonpositive limit' => [['quotaIdentity' => 'zan-global', 'quotaLimit' => 0]],
]);

test('quota limits must be globally consistent for the same identity', function () {
    $limiter = app(QuotaLimiter::class);
    expect($limiter->acquire(new DeliveryPolicy(quotaIdentity: 'zan-global', quotaLimit: 5)))->toBeTrue()
        ->and(fn () => $limiter->acquire(new DeliveryPolicy(quotaIdentity: 'zan-global', quotaLimit: 10)))
        ->toThrow(LogicException::class);
});

test('quota reset begins a new fixed window', function () {
    [, , $property] = organizationWithOwner();
    $policy = new DeliveryPolicy(quotaIdentity: 'zan-global', quotaLimit: 1, quotaWindowSeconds: 30);
    $limiter = app(QuotaLimiter::class);
    expect($limiter->acquire($policy))->toBeTrue()
        ->and($limiter->acquire($policy))->toBeFalse();

    DB::table('outbox_quotas')->where('identity_hash', hash('sha256', 'zan-global'))
        ->update(['window_ends_at' => now()->subSecond()]);

    expect($limiter->acquire($policy))->toBeTrue();
});
