<?php

/**
 * Run after migrating a disposable MySQL database named ticket99_concurrency:
 * APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=ticket99_concurrency
 * DB_HOST=127.0.0.1 DB_PORT=<disposable port> DB_USERNAME=root DB_PASSWORD=<password>
 * DB_URL= php scripts/check-saas-invoice-concurrency.php
 * Requires pcntl. Never uses the dev database.
 */

use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Actions\StartTrial;
use App\Domains\Subscriptions\Models\BillingDetails;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error."\n");
    exit(1);
});
if (DB::connection()->getDatabaseName() !== 'ticket99_concurrency') {
    throw new RuntimeException('This check requires its disposable ticket99_concurrency database.');
}

$organizations = Tenancy::within(TenantContext::platform('Create disposable concurrency fixtures'), function () {
    $owner = User::factory()->create();
    BillingDetails::query()->create(['business_name' => 'Karafuu Ltd', 'address' => 'Zanzibar', 'phone' => '+255', 'email' => 'billing@thedream.test',
        'tin' => '100-200-300', 'accounts' => [['type' => 'mobile_money', 'provider' => 'M-Pesa', 'account_name' => 'Karafuu Ltd', 'account_number' => '0777000111']]]);
    $organizations = [];
    foreach (['Alpha', 'Bravo'] as $name) {
        $organization = Organization::query()->create(['name' => $name, 'country' => 'TZ', 'created_by' => $owner->id]);
        Tenancy::within(TenantContext::forOrganization($organization), fn () => app(StartTrial::class)->handle(null));
        $organizations[] = $organization;
    }

    return $organizations;
});
DB::disconnect();
$children = [];
foreach ([0, 1, 0, 1] as $organizationIndex) {
    $child = pcntl_fork();
    if ($child === -1) {
        throw new RuntimeException('Could not fork a concurrency worker.');
    }
    if ($child === 0) {
        DB::purge();
        usleep(100000);
        try {
            Tenancy::within(TenantContext::forOrganization($organizations[$organizationIndex]), function () {
                for ($iteration = 0; $iteration < 3; $iteration++) {
                    DB::transaction(function () {
                        app(IssueSaasInvoice::class)->handle(Subscription::current());
                        usleep(30000);
                    });
                }
            });
            exit(0);
        } catch (Throwable $error) {
            fwrite(STDERR, $error."\n");
            exit(1);
        }
    }
    $children[] = $child;
}
foreach ($children as $child) {
    pcntl_waitpid($child, $status);
    if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('A concurrent issuer failed.');
    }
}
DB::purge();
Tenancy::within(TenantContext::platform('Verify disposable concurrent invoices'), function () use ($organizations) {
    $numbers = SaasInvoice::query()->orderBy('sequence')->pluck('sequence')->all();
    if ($numbers !== range(1, 12)) {
        throw new RuntimeException('Invoice numbers are not unique and contiguous.');
    }
    foreach ($organizations as $organization) {
        $invoices = SaasInvoice::query()->where('organization_id', $organization->id)->orderBy('period_starts_at')->get();
        if ($invoices->count() !== 6) {
            throw new RuntimeException('Concurrent issuance lost a billing period.');
        }
        foreach ($invoices as $index => $invoice) {
            if ($index > 0 && ! $invoice->period_starts_at->equalTo($invoices[$index - 1]->period_ends_at)) {
                throw new RuntimeException('Concurrent billing periods overlap or have a gap.');
            }
        }
    }
});
echo "PASS: four concurrent issuers, two organizations, 12 unique contiguous numbers and periods.\n";
