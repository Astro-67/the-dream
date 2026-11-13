<?php

use App\Domains\FiscalReceipting\Actions\IssueGeneralReceipt;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['connection']]);
// Records only; delivery is not under test, and ZRA must never be reached from here.
Queue::fake();

file_put_contents($argv[2], 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('General receipt barrier timed out.');
    }
    usleep(10000);
}
$result = Tenancy::within(TenantContext::fromIds($input['organization_id'], $input['property_id']), function () use ($input) {
    try {
        return ['messages' => count(app(IssueGeneralReceipt::class)->forReservation(User::query()->findOrFail($input['actor_id']), Reservation::query()->findOrFail($input['reservation_id'])))];
    } catch (ValidationException $refused) {
        return ['refused' => $refused->errors()['general_receipt'][0] ?? null];
    }
});
echo json_encode($result, JSON_THROW_ON_ERROR);
