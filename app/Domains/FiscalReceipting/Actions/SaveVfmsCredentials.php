<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Saves a property's VFMS Integration ID, Registration ID and Token. A blank
 * token keeps the saved one. The audit entry says whether the token changed, never its value.
 */
class SaveVfmsCredentials
{
    public function handle(User $actor, Property $property, string $integrationId, string $registrationId, ?string $token): VfmsCredential
    {
        return Audit::validationRefusal($actor, $property, 'VFMS credentials refused', function () use ($actor, $property, $integrationId, $registrationId, $token) {
            if (! JurisdictionRules::usesVfms($property)) {
                throw ValidationException::withMessages(['vfms' => __('This property does not use VFMS.')]);
            }
            $credential = VfmsCredential::for($property);
            if (blank($token) && blank($credential->token)) {
                throw ValidationException::withMessages(['token' => __('Enter the VFMS token.')]);
            }

            $credential->fill(['integration_id' => $integrationId, 'registration_id' => $registrationId]);
            if (filled($token)) {
                // New credentials get a fresh chance with VFMS.
                $credential->fill(['token' => $token, 'credentials_refused_at' => null]);
            }
            $tokenChanged = $credential->isDirty('token');
            $credential->updated_by = $actor->id;
            Tenancy::within(TenantContext::forProperty($property), fn () => $credential->save());

            Audit::record($actor, 'vfms_credentials_saved', $property, 'VFMS credentials saved', [
                'property_id' => $property->id,
                'attributes' => ['integration_id' => $integrationId, 'registration_id' => $registrationId, 'token_changed' => $tokenChanged],
            ]);

            return $credential;
        });
    }
}
