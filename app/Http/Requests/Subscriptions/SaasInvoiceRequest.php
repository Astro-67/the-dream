<?php

namespace App\Http\Requests\Subscriptions;

use App\Domains\Subscriptions\Models\SaasInvoice;
use Illuminate\Foundation\Http\FormRequest;

class SaasInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', SaasInvoice::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['organization_id' => ['required', 'ulid', 'exists:organizations,id']];
    }
}
