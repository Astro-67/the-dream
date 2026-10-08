<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class RestoreGate
{
    public function isClosed(string $propertyId): bool
    {
        return DB::table('outbox_restore_gates')->where('property_id', $propertyId)->where('is_closed', true)->exists();
    }

    public function close(Property $property, string $reason): void
    {
        DB::table('outbox_restore_gates')->updateOrInsert(
            ['property_id' => $property->id],
            [
                'id' => (string) Str::ulid(),
                'organization_id' => $property->organization_id,
                'property_id' => $property->id,
                'is_closed' => true,
                'delivery_reconciled_at' => null,
                'identity_safe_at' => null,
                'opened_at' => null,
                'last_reason' => $reason,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function assertOpen(string $propertyId): void
    {
        if ($this->isClosed($propertyId)) {
            throw new LogicException('Outbox restore gate is closed for this property.');
        }
    }
}
