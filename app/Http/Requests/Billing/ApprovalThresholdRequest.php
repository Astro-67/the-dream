<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\PostingApproval;
use App\Http\Requests\AuditsRefusals;
use App\Support\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApprovalThresholdRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('configure', PostingApproval::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::enum(Currency::class)],
            'amount' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }
}
