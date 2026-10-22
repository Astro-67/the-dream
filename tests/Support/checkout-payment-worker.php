<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['connection']]);

function awaitCheckoutPaymentBarrier(string $path): void
{
    $deadline = microtime(true) + 15;
    while (! file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Checkout/payment barrier timed out.');
        }
        usleep(10000);
    }
}

file_put_contents($argv[2], 'ready');
awaitCheckoutPaymentBarrier($argv[3]);
$result = Tenancy::within(TenantContext::fromIds($input['organization_id'], $input['property_id']), function () use ($input, $argv) {
    $actor = User::query()->findOrFail($input['actor_id']);
    if ($argv[4] === '0') {
        awaitCheckoutPaymentBarrier($argv[3].'.snapshot');
        app(RecordPayment::class)->handle($actor, Folio::query()->findOrFail($input['folio_id']), [
            'method' => $input['method'], 'amount' => 1500, 'currency' => 'USD', 'idempotency_key' => 'checkout-payment',
        ]);
        touch($argv[3].'.paid');

        return ['paid' => true];
    }

    return DB::transaction(function () use ($input, $argv, $actor) {
        $reservation = Reservation::query()->findOrFail($input['reservation_id']);
        touch($argv[3].'.snapshot');
        awaitCheckoutPaymentBarrier($argv[3].'.paid');

        return ['status' => app(CheckOut::class)->handle($actor, $reservation)->status->value];
    });
});
echo json_encode($result, JSON_THROW_ON_ERROR);
