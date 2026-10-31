<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\HttpVfms;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Checks a property's saved VFMS credentials with ZRA (read-only, nothing filed) and stores
 * accepted, refused or unreachable with its time. At most one check per property per minute.
 */
class CheckVfmsCredentials
{
    public function __construct(private HttpVfms $vfms) {}

    public static function throttled(Property $property): bool
    {
        return RateLimiter::tooManyAttempts(self::key($property), 1);
    }

    public function handle(User $actor, Property $property): VfmsCredential
    {
        return Audit::validationRefusal($actor, $property, 'ZRA credential check refused', function () use ($actor, $property) {
            $credential = VfmsCredential::for($property);
            if (! JurisdictionRules::usesVfms($property)) {
                throw ValidationException::withMessages(['zra_check' => __('This property does not use VFMS.')]);
            }
            if (! $credential->isComplete()) {
                throw ValidationException::withMessages(['zra_check' => __('Save the Integration ID, Registration ID and Token before checking with ZRA.')]);
            }
            if (self::throttled($property)) {
                throw ValidationException::withMessages(['zra_check' => __('ZRA was checked less than a minute ago. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn(self::key($property))])]);
            }
            RateLimiter::hit(self::key($property), 60);

            $outcome = $this->vfms->checkCredentials($credential);
            $credential->forceFill(['zra_check' => $outcome, 'zra_checked_at' => now()]);
            Tenancy::within(TenantContext::forProperty($property), fn () => $credential->save());

            Audit::record($actor, 'vfms_credentials_checked', $property, 'VFMS credentials checked with ZRA', [
                'property_id' => $property->id,
                'attributes' => ['zra_check' => $outcome, 'integration_id' => $credential->integration_id],
            ]);

            return $credential;
        });
    }

    private static function key(Property $property): string
    {
        return 'zra-credential-check:'.$property->id;
    }
}
