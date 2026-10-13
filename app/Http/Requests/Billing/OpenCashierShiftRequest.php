<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\CashierShift;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashierShiftRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('create', CashierShift::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'floats' => ['required', 'array', 'min:1'],
            'floats.*' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }

    /** @return array<string, int> */
    public function floats(): array
    {
        /** @var array<string, int|string> $floats */
        $floats = $this->validated('floats');

        return array_map('intval', $floats);
    }
}
