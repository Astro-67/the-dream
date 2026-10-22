<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['connection']]);
file_put_contents($argv[2], 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Payment start barrier timed out.');
    }
    usleep(10000);
}
$receipt = Tenancy::within(TenantContext::fromIds($input['organization_id'], $input['property_id']), fn () => app(RecordPayment::class)->handle(
    User::query()->findOrFail($input['actor_id']), Folio::query()->findOrFail($input['folio_id']),
    ['method' => 'bank_transfer', 'amount' => $input['amounts'][(int) $argv[4]], 'currency' => 'USD', 'idempotency_key' => 'parallel-'.$argv[4]],
)->receipt);
echo json_encode(['sequence' => $receipt->sequence, 'summary' => $receipt->summary], JSON_THROW_ON_ERROR);
