<?php

use App\Domains\Onboarding\Actions\CommitImport;
use App\Domains\Onboarding\Models\ImportJob;
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
        throw new RuntimeException('Import start barrier timed out.');
    }
    usleep(10000);
}
$result = Tenancy::within(TenantContext::fromIds($input['organization_id'], $input['property_id']), function () use ($input, $argv) {
    $job = ImportJob::query()->findOrFail($input['job_ids'][(int) $argv[4]]);
    app(CommitImport::class)->handle(User::query()->findOrFail($input['actor_id']), $job);

    return $job->rows()->sole()->commit_result;
});
echo json_encode($result, JSON_THROW_ON_ERROR);
