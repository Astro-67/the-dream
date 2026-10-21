<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class UpdateInfrastructureLevy
{
    public function handle(?User $actor, Property $property): void
    {
        $levy = JurisdictionRules::defaultInfrastructureLevy($property);
        if ($levy === null) {
            return;
        }

        Tenancy::within(TenantContext::forProperty($property), function () use ($actor, $property, $levy): void {
            DB::transaction(function () use ($actor, $property, $levy): void {
                Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
                $rules = TaxRule::query()->where('property_id', $property->id)->where('name', 'Infrastructure levy')->orderByDesc('effective_from')->get();
                foreach ($rules as $rule) {
                    if ($rule->isInfrastructureLevy() && $rule->effective_from->toDateString() === $property->business_date->addDay()->toDateString()) {
                        $previous = $rule->amount;
                        $rule->update(['amount' => $levy]);
                        Audit::record($actor, 'updated', $rule, 'Scheduled infrastructure levy updated', ['old' => ['amount' => $previous], 'attributes' => ['amount' => $levy]]);
                        break;
                    }
                    if (! $rule->isInfrastructureLevy() || ! $rule->coversDate($property->business_date->toDateString()) || $rule->amount === $levy) {
                        continue;
                    }
                    // A rule cannot be versioned on its first day; keep that day's estimate intact.
                    $from = $property->business_date->max($rule->effective_from->addDay())->toDateString();
                    app(ChangeTaxRule::class)->handle($actor, $rule, [
                        'name' => $rule->name, 'kind' => $rule->kind->value, 'rate' => $rule->rate,
                        'amount' => $levy, 'currency' => $rule->currency?->value, 'inclusive' => $rule->inclusive,
                        'applies_to' => $rule->applies_to->value, 'rounding' => $rule->rounding->value,
                        'zra_tax_category' => $rule->zra_tax_category?->value,
                        'effective_from' => $from, 'effective_to' => $rule->effective_to?->toDateString(),
                    ]);
                    break;
                }
            });
        });
    }
}
