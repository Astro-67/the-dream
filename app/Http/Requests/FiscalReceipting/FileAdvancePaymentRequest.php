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
        $link = $this->boolean('link_existing');

        return [
            'link_existing' => ['sometimes', 'boolean'],
            'email' => $link ? ['exclude'] : ['required', 'email', 'max:255'],
            'phone' => $link ? ['exclude'] : ['required', 'string', 'max:50'],
            'is_zra_registered' => $link ? ['exclude'] : ['required', 'boolean'],
            'booking_number' => $link ? ['required', 'string', 'max:255'] : ['exclude'],
            'receipt_number' => $link ? ['required', 'string', 'max:255'] : ['exclude'],
            'reason' => $link ? ['required', 'string', 'max:500'] : ['exclude'],
        ];
    }
}
