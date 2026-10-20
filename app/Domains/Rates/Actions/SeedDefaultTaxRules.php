<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

class SeedDefaultTaxRules
{
    public function handle(?User $actor, Property $property): void
    {
        $levy = JurisdictionRules::defaultInfrastructureLevy($property);
        if ($levy === null) {
            return;
        }

        // Creation owns the transaction; defaults never replace an existing property's rules.
        Tenancy::within(TenantContext::forProperty($property), function () use ($actor, $property, $levy): void {
            $base = ['rounding' => 'half_up', 'effective_from' => $property->business_date->toDateString(), 'effective_to' => null];
            app(CreateTaxRule::class)->handle($actor, [...$base,
                'name' => 'VAT', 'kind' => 'percentage', 'rate' => 1500, 'amount' => null, 'currency' => null,
                'inclusive' => true, 'applies_to' => 'both', 'zra_tax_category' => 'vat',
            ]);
            app(CreateTaxRule::class)->handle($actor, [...$base,
                'name' => 'Infrastructure levy', 'kind' => 'flat_per_person_night', 'rate' => null, 'amount' => $levy, 'currency' => 'USD',
                'inclusive' => false, 'applies_to' => 'room', 'zra_tax_category' => 'hotel_levy',
            ]);
        });
    }
}
