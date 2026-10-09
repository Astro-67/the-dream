<?php

namespace App\Http\Requests\Rates;

use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\Models\TaxRule;
use App\Support\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', TaxRule::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Every field is optional together: an empty form shows no breakdown yet.
        $with = 'required_with:amount,currency,date,guests,charge';

        return [
            'amount' => [$with, 'integer', 'min:0', 'max:1000000000'],
            'currency' => [$with, Rule::enum(Currency::class)],
            'date' => [$with, 'date_format:Y-m-d'],
            'guests' => [$with, 'integer', 'between:1,40'],
            'charge' => [$with, Rule::enum(ChargeKind::class)],
        ];
    }
}
