<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\TaxRule;
use App\Domains\Rates\TaxInclusion;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts VAT or the infrastructure levy inside or on top of the room rate from a date, by versioning
 * every room-covering rule in force that differs. Earlier dates, later scheduled versions and posted
 * tax keep their terms; all versions change together or none do.
 */
class SetTaxInclusion
{
    /** @param array<string, bool|string|null> $choices  by TaxInclusion control, a missing or null control staying as it is, plus an optional effective_from */
    public function handle(?User $actor, Property $property, array $choices): void
    {
        $from = is_string($choices['effective_from'] ?? null) ? $choices['effective_from'] : null;
        Tenancy::within(TenantContext::forProperty($property), function () use ($actor, $property, $choices, $from): void {
            DB::transaction(function () use ($actor, $property, $choices, $from): void {
                Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
                $old = $new = $starts = [];
                foreach (TaxInclusion::CONTROLS as $control) {
                    $current = TaxInclusion::rules($property, $control)['current'];
                    $changing = $current->filter(fn (TaxRule $rule) => isset($choices[$control]) && $rule->inclusive !== (bool) $choices[$control]);
                    if ($changing->isEmpty()) {
                        continue;
                    }
                    $earliest = TaxInclusion::earliest($property, $changing);
                    $start = $from ?? $earliest;
                    if ($start < $earliest) {
                        throw ValidationException::withMessages(['tax_inclusion.effective_from' => __('The change can start on :date at the earliest.', ['date' => $earliest])]);
                    }
                    $old[$control] = TaxInclusion::state($current);
                    $new[$control] = $choices[$control] ? 'included' : 'added';
                    $starts[$control] = $start;
                    foreach ($changing as $rule) {
                        try {
                            // Keeping the original end leaves any later scheduled version untouched.
                            app(ChangeTaxRule::class)->handle($actor, $rule, [...$rule->terms(), 'inclusive' => (bool) $choices[$control], 'effective_from' => $start]);
                        } catch (ValidationException $refused) {
                            throw ValidationException::withMessages(['tax_inclusion.effective_from' => $refused->validator->errors()->first()]);
                        }
                    }
                }
                if ($new !== []) {
                    Audit::record($actor, 'updated', $property, 'Tax inclusion changed', ['old' => $old, 'attributes' => $new, 'effective_from' => $starts]);
                }
            });
        });
    }
}
