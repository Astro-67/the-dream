<?php

use App\Domains\FrontDesk\Actions\ExtendStay;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['connection']]);
file_put_contents($argv[2], 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Extension start barrier timed out.');
    }
    usleep(10000);
}
try {
    $number = Tenancy::within(TenantContext::fromIds($input['organization_id'], $input['property_id']), function () use ($input) {
        $actor = User::query()->findOrFail($input['actor_id']);

        return isset($input['reservation_id'])
            ? app(ExtendStay::class)->handle($actor, Reservation::query()->findOrFail($input['reservation_id']), $input['departure'])->reservation_number
            : app(CreateReservation::class)->handle($actor, $input['details'])->reservation_number;
    });
    echo json_encode(['number' => $number], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['refused' => $exception->errors()], JSON_THROW_ON_ERROR);
}
