<?php

use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\Models\CustomerAccount;
use App\Support\Outbox\OutboxMessage;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

$attackAccount = function (TwoTenants $tenants, string $route) {
    [$account, $message] = inTenant($tenants->b->property, function () use ($tenants) {
        $account = app(OpenCustomerAccount::class)->handle($tenants->b->owner, [
            'kind' => 'event', 'customer_name' => 'Bravo Conference', 'currency' => 'USD',
        ]);
        $message = OutboxMessage::query()->create([
            'integration' => 'vfms', 'subject_type' => $account->getMorphClass(), 'subject_id' => $account->id,
            'sequence' => 1, 'stream_key' => hash('sha256', $account->id), 'idempotency_key' => 'isolation:'.$account->id,
            'event' => 'generalReceipt', 'payload' => ['customer_account_id' => $account->id], 'status' => 'rejected',
        ]);

        return [$account, $message];
    });
    $responses = [];
    foreach ($tenants->a->members() as $member) {
        $url = route($route, str_contains($route, '.vfms.') ? [$account, $message] : $account);
        $responses[] = $route === 'customer-accounts.show'
            ? $this->actingAs($member)->get($url)->assertNotFound()
            : $this->actingAs($member)->post($url, ['_property' => $tenants->a->property->id, 'reason' => 'Foreign account attack'])->assertNotFound();
    }
    inTenant($tenants->b->property, function () use ($account, $message) {
        expect($account->fresh()->status)->toBe('open')->and($message->fresh()->status)->toBe('rejected')
            ->and($message->revisions()->count())->toBe(0);
    });

    return $responses;
};

return [
    'customer-accounts.index' => RouteCoverage::visit(),
    'customer-accounts.store' => RouteCoverage::attempt(function (TwoTenants $tenants) {
        $responses = [];
        foreach ($tenants->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('customer-accounts.store'), [
                '_property' => $tenants->b->property->id, 'kind' => 'day_use', 'customer_name' => 'Attack', 'currency' => 'USD',
            ])->assertForbidden();
        }
        expect(inTenant($tenants->b->property, fn () => CustomerAccount::query()->count()))->toBe(0);

        return $responses;
    }),
    'customer-accounts.show' => RouteCoverage::attempt(fn (TwoTenants $tenants) => $attackAccount->call($this, $tenants, 'customer-accounts.show')),
    'customer-accounts.close' => RouteCoverage::attempt(fn (TwoTenants $tenants) => $attackAccount->call($this, $tenants, 'customer-accounts.close')),
    'customer-accounts.vfms.retry' => RouteCoverage::attempt(fn (TwoTenants $tenants) => $attackAccount->call($this, $tenants, 'customer-accounts.vfms.retry')),
    'customer-accounts.vfms.refile' => RouteCoverage::attempt(fn (TwoTenants $tenants) => $attackAccount->call($this, $tenants, 'customer-accounts.vfms.refile')),
];
