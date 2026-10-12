<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Models\RoomStatus;

class ReconcileRoomServiceStatuses
{
    public function handle(): void
    {
        RoomStatus::query()->with('room')->each(fn (RoomStatus $status) => app(SyncRoomServiceStatus::class)->handle(null, $status->room));
    }
}
