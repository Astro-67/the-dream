<?php

namespace App\Http\Requests\Rates;

use App\Domains\Rates\Models\TaxRule;
use Illuminate\Foundation\Http\FormRequest;

class EndTaxRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('taxRule');

        return $rule instanceof TaxRule && ($this->user()?->can('update', $rule) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['effective_to' => ['required', 'date_format:Y-m-d']];
    }
}
