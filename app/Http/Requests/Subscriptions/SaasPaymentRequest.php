<?php

namespace App\Http\Requests\Subscriptions;

use App\Domains\Subscriptions\Enums\SaasPaymentMethod;
use App\Domains\Subscriptions\Models\SaasInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaasPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', SaasInvoice::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(SaasPaymentMethod::class)],
            'reference' => ['required', 'string', 'max:100'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
