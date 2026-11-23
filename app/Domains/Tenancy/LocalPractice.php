<?php

namespace App\Domains\Tenancy;

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;

/** Local setup stays remain test events and never reach integration translators. */
final class LocalPractice
{
    public static function allows(Property $property): bool
    {
        return app()->environment('local')
            && config('app.local_practice') === true
            && $property->status === PropertyStatus::Setup
            && $property->archived_at === null
            && VfmsCredential::for($property)->mode === VfmsMode::Sandbox;
    }
}
