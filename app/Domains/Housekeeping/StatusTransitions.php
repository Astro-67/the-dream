<?php

namespace App\Domains\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use Illuminate\Validation\ValidationException;

/** The single owner of permitted housekeeping transitions. */
class StatusTransitions
{
    public function ensureAllowed(HousekeepingStatus $from, HousekeepingStatus $to, bool $serviceChanged = false): void
    {
        $allowed = $serviceChanged ? ($to === HousekeepingStatus::Unavailable || ($from === HousekeepingStatus::Unavailable && $to === HousekeepingStatus::Dirty)) : match ($from) {
            HousekeepingStatus::Dirty => $to === HousekeepingStatus::Clean,
            HousekeepingStatus::Clean => $to === HousekeepingStatus::Dirty,
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages(['status' => __('Changing housekeeping status from :from to :to is not allowed.', [
                'from' => $from->label(), 'to' => $to->label(),
            ])]);
        }
    }
}
