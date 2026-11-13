<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\CustomerAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CustomerAccount::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::in(['day_use', 'event'])],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
        ];
    }
}
