<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class CloseCustomerAccountRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('close', $this->route('customerAccount')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
