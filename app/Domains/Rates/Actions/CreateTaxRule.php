<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a rule to the property in context. Rules sharing a name never overlap in effective dates,
 * so the calculator finds at most one version of each tax on any date.
 */
class CreateTaxRule
{
    /** @param array{name: string, kind: string, rate: int|null, amount: int|null, currency: string|null, inclusive: bool, applies_to: string, rounding: string, effective_from: string, effective_to: string|null} $details */
    public function handle(?User $actor, array $details): TaxRule
    {
        return Audit::validationRefusal($actor, Tenancy::property() ?? new Property, 'Tax rule creation refused', fn () => DB::transaction(function () use ($actor, $details) {
            // Serialize rule writes per property: an overlap check can't lock rows that don't exist yet.
            Property::query()->whereKey(Tenancy::property()?->id)->lockForUpdate()->firstOrFail();
            self::refuseOverlap($details['name'], $details['effective_from'], $details['effective_to']);
            $rule = TaxRule::query()->create($details);
            Audit::record($actor, 'created', $rule, 'Tax rule created', ['attributes' => $details]);

            return $rule;
        }));
    }

    /** @throws ValidationException when another rule of the name is in force on any of the dates */
    public static function refuseOverlap(string $name, string $from, ?string $to, ?TaxRule $except = null, string $field = 'effective_from'): void
    {
        $clash = TaxRule::query()->where('name', $name)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('effective_from', '<=', $to))
            ->first();
        if ($clash !== null) {
            throw ValidationException::withMessages([$field => __(':tax already applies from :from to :to. Rules sharing a name cannot overlap.', [
                'tax' => $name, 'from' => $clash->effective_from->format('Y-m-d'), 'to' => $clash->effective_to?->format('Y-m-d') ?? __('no end date'),
            ])]);
        }
    }
}
