<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Models\Property;

final class PreventArchivingWithUnresolvedMessages implements PreArchiveCheck
{
    public function blockingReason(Property $property): ?string
    {
        $unresolved = OutboxMessage::query()
            // Allowlist the resolved states so any new status blocks archival by default.
            ->whereNotIn('status', ['delivered', 'superseded'])
            ->exists();

        return $unresolved
            ? __('This property has unresolved integration messages. Resolve them before archiving the property.')
            : null;
    }
}
