<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Billing\Enums\CashierShiftStatus;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Rates\Actions\SetTaxInclusion;
use App\Domains\Rates\Actions\UpdateInfrastructureLevy;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a property's details and records the changed fields, before and
 * after, in the audit trail. Tax inclusion choices change its tax rules in the same transaction.
 */
class UpdateProperty
{
    /** @param array{vat?: bool|null, effective_from?: string|null} $taxInclusion */
    public function handle(?User $actor, Property $property, PropertyDetails $details, array $taxInclusion = []): Property
    {
        return Audit::validationRefusal($actor, $property, 'Property update refused', fn () => DB::transaction(function () use ($actor, $property, $details, $taxInclusion) {
            $property = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($taxInclusion !== []) {
                app(SetTaxInclusion::class)->handle($actor, $property, $taxInclusion);
            }
            if ($details->jurisdictionProfile !== $property->jurisdiction_profile) {
                JurisdictionRules::ensureProfileCanChange($property, $details->jurisdictionProfile);
            }

            $attributes = $details->toAttributes();
            foreach ($property->settings() as $group => $current) {
                if (isset($attributes[$group])) {
                    $attributes[$group] = array_replace($current, $attributes[$group]);
                }
            }
            if ($property->settings()['payment_settings']['use_cashier_shifts']
                && ($attributes['payment_settings']['use_cashier_shifts'] ?? true) === false
                && Tenancy::within(TenantContext::forProperty($property), fn () => CashierShift::query()
                    ->where('property_id', $property->id)->where('status', CashierShiftStatus::Open)->lockForUpdate()->first(['id']) !== null)) {
                throw ValidationException::withMessages([
                    'payment_settings.use_cashier_shifts' => __('Close every open cashier shift at this property before turning cashier shifts off.'),
                ]);
            }
            $property->fill($attributes);
            $changed = array_keys($property->getDirty());

            if ($changed === []) {
                return $property;
            }

            $old = array_map(fn (string $key) => $property->getOriginal($key), array_combine($changed, $changed));
            $property->save();

            if (in_array('star_rating', $changed, true)) {
                app(UpdateInfrastructureLevy::class)->handle($actor, $property);
            }

            Audit::record($actor, 'updated', $property, 'Property updated', [
                'result' => 'success',
                'property_id' => $property->getKey(),
                'old' => $old,
                'attributes' => $property->only($changed),
            ]);

            return $property;
        }));
    }
}
