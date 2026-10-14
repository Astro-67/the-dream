<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Actions\RestoreProperty;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Models\Property;
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
$task = $input['tasks'][(int) substr($argv[2], -1)];
file_put_contents($argv[2], 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Entitlement start barrier timed out.');
    }
    usleep(10000);
}
try {
    $id = Tenancy::within(TenantContext::fromIds($input['organization_id'], $task['property_id'] ?? null), function () use ($input, $task) {
        $actor = User::query()->findOrFail($input['actor_id']);

        return match ($task['action']) {
            'property' => app(CreateProperty::class)->handle($actor, PropertyDetails::fromArray($task['details'])),
            'restore' => app(RestoreProperty::class)->handle($actor, Property::query()->findOrFail($task['restore_id'])),
            'room' => app(CreateRoom::class)->handle($actor, $task['details']),
        };
    })->id;
    echo json_encode(['id' => $id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['refused' => $exception->errors()], JSON_THROW_ON_ERROR);
}
