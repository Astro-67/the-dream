<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class RestoreGate
{
    public function isClosed(string $propertyId): bool
    {
        return DB::table('outbox_restore_gates')->where('property_id', $propertyId)->where('is_closed', true)->exists();
    }

    public function close(Property $property, string $reason, User $actor): void
    {
        Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
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
        $messages = OutboxMessage::query()->where('property_id', $property->id)->where('status', 'sending')->lockForUpdate()->get();
        foreach ($messages as $message) {
            $message->attempts()->where('attempt_number', $message->attempt_count)->where('outcome', 'sending')->update(['outcome' => 'unknown', 'updated_at' => now()]);
            $message->status = 'unknown';
            $message->claim_token = null;
            $message->claim_expires_at = null;
            $message->setAttribute('dispatch_claim_token', null);
            $message->setAttribute('dispatch_claim_expires_at', null);
            $message->save();
        }
        Audit::record($actor, 'outbox_restore_gate_closed', $property, 'Closed outbox restore gate', [
            'reason' => $reason,
            'in_flight_marked_unknown' => $messages->count(),
        ]);
    }

    public function recordDeliveryReconciled(Property $property, User $actor, string $reason): void
    {
        DB::table('outbox_restore_gates')->where('property_id', $property->id)->update([
            'delivery_reconciled_at' => now(),
            'last_reason' => $reason,
            'updated_at' => now(),
        ]);
        Audit::record($actor, 'outbox_restore_delivery_reconciled', $property, 'Reconciled outbox delivery after restore', [
            'reason' => $reason,
            'unresolved' => DB::table('outbox_messages')->where('property_id', $property->id)->whereNotIn('status', ['delivered', 'superseded'])->count(),
        ]);
    }

    public function open(Property $property, User $actor, string $reason): void
    {
        Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
        $gate = DB::table('outbox_restore_gates')->where('property_id', $property->id)->lockForUpdate()->first();
        if ($gate === null || ! $gate->is_closed) {
            throw new LogicException('The restore gate is not closed.');
        }
        if ($gate->delivery_reconciled_at === null || $gate->identity_safe_at === null) {
            throw new LogicException('Delivery reconciliation and identity safety are required before reopening the restore gate.');
        }
        $unsafe = DB::table('outbox_messages as messages')
            ->where('messages.property_id', $property->id)
            ->whereIn('messages.status', ['unknown', 'exhausted_unknown', 'rejected', 'exhausted', 'blocked', 'sending'])
            ->exists();
        if ($unsafe) {
            throw new LogicException('Unresolved or blocked outbox messages prevent reopening the restore gate.');
        }

        $pending = DB::table('outbox_messages as messages')
            ->where('messages.property_id', $property->id)
            ->whereIn('messages.status', ['pending', 'retryable'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('outbox_identity_floors as floors')
                ->whereColumn('floors.organization_id', 'messages.organization_id')
                ->whereColumn('floors.property_id', 'messages.property_id')
                ->whereColumn('floors.integration', 'messages.integration')
                ->whereColumn('floors.subject_type', 'messages.subject_type')
                ->whereColumn('floors.subject_id', 'messages.subject_id'))
            ->exists();
        if ($pending) {
            throw new LogicException('Pending replay requires a matching verified identity floor before reopening the restore gate.');
        }

        $underfloored = DB::table('outbox_messages as messages')
            ->join('outbox_identity_floors as floors', function ($join): void {
                $join->on('floors.organization_id', '=', 'messages.organization_id')
                    ->on('floors.property_id', '=', 'messages.property_id')
                    ->on('floors.integration', '=', 'messages.integration')
                    ->on('floors.subject_type', '=', 'messages.subject_type')
                    ->on('floors.subject_id', '=', 'messages.subject_id');
            })
            ->where('messages.property_id', $property->id)
            ->whereColumn('messages.sequence', '>', 'floors.highest_sequence')
            ->exists();
        if ($underfloored) {
            throw new LogicException('Restored sequence history exceeds the verified identity floor.');
        }

        DB::table('outbox_restore_gates')->where('property_id', $property->id)->update([
            'is_closed' => false,
            'opened_at' => now(),
            'last_reason' => $reason,
            'updated_at' => now(),
        ]);
        Audit::record($actor, 'outbox_restore_gate_opened', $property, 'Reopened outbox restore gate', ['reason' => $reason]);
    }

    public function assertOpen(string $propertyId): void
    {
        if ($this->isClosed($propertyId)) {
            throw new LogicException('Outbox restore gate is closed for this property.');
        }
    }
}
