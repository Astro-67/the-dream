<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Models\Property;

final class PreventArchivingWithUnresolvedMessages implements PreArchiveCheck
{
    public function blockingReason(Property $property): ?string
    {
        $unresolved = OutboxMessage::query()
            ->whereIn('status', ['pending', 'sending', 'rejected', 'exhausted', 'exhausted_unknown', 'blocked', 'unknown'])
            ->exists();

        return $unresolved
            ? __('This property has unresolved integration messages. Resolve them before archiving the property.')
            : null;
    }
}
