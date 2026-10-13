<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class ResolveFeeRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('resolve', $this->route('feeProposal')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:post,waive'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
