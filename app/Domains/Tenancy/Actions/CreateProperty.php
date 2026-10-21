<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Subscriptions\Entitlements;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Adds a property to the organization in the current tenant context.
 * A demo property is flagged here, at creation, and never after (GLOSSARY: demo property).
 */
class CreateProperty
{
    public function handle(?User $actor, PropertyDetails $details, bool $demo = false): Property
    {
        return Audit::validationRefusal($actor, new Property, 'Property creation refused', fn () => DB::transaction(function () use ($actor, $details, $demo) {
            app(Entitlements::class)->assertCanAdd($demo, properties: 1);
            $property = (new Property([...$details->toAttributes(), 'business_date' => now($details->timezone)->toDateString()]))->forceFill(['demo' => $demo]);
            $property->fill($property->settings());
            $property->save();
            app(SeedDefaultTaxRules::class)->handle($actor, $property);

            Audit::record($actor, 'created', $property, 'Property created', [
                'result' => 'success',
                'property_id' => $property->getKey(),
                'attributes' => $property->only([
                    'name', 'property_type', 'address_line', 'city', 'region', 'postal_code',
                    'phone', 'email', 'timezone', 'currency', 'jurisdiction_profile', 'zra_hotel_code', 'demo',
                ]),
            ]);

            return $property;
        }, attempts: 3));
    }
}
