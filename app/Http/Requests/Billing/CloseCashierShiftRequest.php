<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashierShiftRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('close', $this->route('cashierShift')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'counted' => ['required', 'array', 'min:1'],
            'counted.*' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'variance_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, int> */
    public function counted(): array
    {
        /** @var array<string, int|string> $counted */
        $counted = $this->validated('counted');

        return array_map('intval', $counted);
    }
}
