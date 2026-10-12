<?php

namespace App\Domains\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use Illuminate\Validation\ValidationException;

/** The single owner of permitted housekeeping transitions. */
class StatusTransitions
{
    public function ensureAllowed(HousekeepingStatus $from, HousekeepingStatus $to, bool $inspectionRequired = false, bool $occupancyLifecycle = false): void
    {
        $allowed = $occupancyLifecycle ? (
            ($to === HousekeepingStatus::Occupied && in_array($from, [HousekeepingStatus::Clean, HousekeepingStatus::Dirty, HousekeepingStatus::InspectionRequired], true))
            || ($from === HousekeepingStatus::Occupied && $to === HousekeepingStatus::Dirty)
        ) : match ($from) {
            HousekeepingStatus::Dirty => $to === ($inspectionRequired ? HousekeepingStatus::InspectionRequired : HousekeepingStatus::Clean),
            HousekeepingStatus::InspectionRequired => in_array($to, [HousekeepingStatus::Clean, HousekeepingStatus::Dirty], true),
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
