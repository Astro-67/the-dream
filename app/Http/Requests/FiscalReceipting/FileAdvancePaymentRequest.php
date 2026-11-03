<?php

namespace App\Http\Requests\FiscalReceipting;

use App\Domains\Billing\Models\Receipt;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class FileAdvancePaymentRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $receipt = $this->route('receipt');

        return $receipt instanceof Receipt && ($this->user()?->can('fileAdvance', $receipt) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'is_zra_registered' => ['required', 'boolean'],
        ];
    }
}
