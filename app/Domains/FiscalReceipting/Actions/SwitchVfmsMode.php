<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Switches a property between Sandbox and Live, audited with the actor and reason.
 * Live needs a Zanzibar property and all three credentials; the only way to Live is this action.
 */
class SwitchVfmsMode
{
    public function handle(User $actor, Property $property, VfmsMode $mode, string $reason): VfmsCredential
    {
        return Audit::validationRefusal($actor, $property, 'VFMS mode change refused', function () use ($actor, $property, $mode, $reason) {
            $credential = VfmsCredential::for($property);
            if ($mode === VfmsMode::Live && ! JurisdictionRules::usesVfms($property)) {
                throw ValidationException::withMessages(['mode' => __('This property does not use VFMS.')]);
            }
            if ($mode === VfmsMode::Live && ! $credential->isComplete()) {
                throw ValidationException::withMessages(['mode' => __('Save the Integration ID, Registration ID and Token before switching to Live.')]);
            }
            if ($credential->mode === $mode) {
                return $credential;
            }

            $from = $credential->mode;
            $credential->fill(['mode' => $mode, 'live_since' => $mode === VfmsMode::Live ? now() : null]);
            $credential->updated_by = $actor->id;
            Tenancy::within(TenantContext::forProperty($property), fn () => $credential->save());

            Audit::record($actor, 'vfms_mode_changed', $property, 'VFMS mode changed', [
                'property_id' => $property->id,
                'reason' => $reason,
                'old' => ['mode' => $from->value],
                'attributes' => ['mode' => $mode->value],
            ]);

            return $credential;
        });
    }
}
