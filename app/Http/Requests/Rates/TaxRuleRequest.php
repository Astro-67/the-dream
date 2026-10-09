<?php

namespace App\Http\Requests\Rates;

use App\Domains\Rates\Enums\TaxAppliesTo;
use App\Domains\Rates\Enums\TaxKind;
use App\Domains\Rates\Enums\TaxRounding;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new tax rule, or the next version of the routed rule. The rate is a percentage with up to two decimals (stored in basis points);
 * a flat amount is in minor units of its currency per guest per night.
 */
class TaxRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('taxRule');

        return $this->user()?->can(...($rule instanceof TaxRule ? ['update', $rule] : ['create', TaxRule::class])) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $property = Tenancy::property();
        $flat = $this->input('kind') === TaxKind::FlatPerPersonNight->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(TaxKind::class)],
            'rate' => $flat ? ['prohibited'] : ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'amount' => $flat ? ['required', 'integer', 'min:0', 'max:1000000000'] : ['prohibited'],
            'currency' => $flat ? ['required', Rule::in($property ? JurisdictionRules::allowedCurrencies($property) : [])] : ['prohibited'],
            'inclusive' => ['required', 'boolean'],
            'applies_to' => ['required', Rule::enum(TaxAppliesTo::class)],
            'rounding' => ['required', Rule::enum(TaxRounding::class)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array{name: string, kind: string, rate: int|null, amount: int|null, currency: string|null, inclusive: bool, applies_to: string, rounding: string, effective_from: string, effective_to: string|null} */
    public function details(): array
    {
        /** @var array{name: string, kind: string, rate?: numeric-string, amount?: int|string, currency?: string, applies_to: string, rounding: string, effective_from: string, effective_to?: string|null} $valid */
        $valid = $this->validated();

        return [
            'name' => $valid['name'], 'kind' => $valid['kind'],
            'rate' => isset($valid['rate']) ? (int) round((float) $valid['rate'] * 100) : null,
            'amount' => isset($valid['amount']) ? (int) $valid['amount'] : null,
            'currency' => $valid['currency'] ?? null,
            'inclusive' => $this->boolean('inclusive'), 'applies_to' => $valid['applies_to'], 'rounding' => $valid['rounding'],
            'effective_from' => $valid['effective_from'], 'effective_to' => $valid['effective_to'] ?? null,
        ];
    }
}
