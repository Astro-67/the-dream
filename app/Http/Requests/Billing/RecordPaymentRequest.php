<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('recordPayment', $this->route('folio')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(RecordPayment::METHODS)],
            // The channel-collected amount comes from the reservation, never the form.
            'amount' => ['exclude_if:method,channel_collected', 'required', 'integer', 'min:1', 'max:1000000000000'],
            'reference' => ['nullable', 'string', 'max:255'],
            // ADR 0004: masked last four digits and brand only.
            'card_last_four' => ['exclude_unless:method,card', 'nullable', 'digits:4'],
            'card_brand' => ['exclude_unless:method,card', 'nullable', 'string', 'max:20'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array{method: string, amount: int|null, currency: string, reference: string|null, card_last_four: string|null, card_brand: string|null, idempotency_key: string} */
    public function payment(): array
    {
        /** @var array{method: string, amount?: int|string, reference?: string|null, card_last_four?: string|null, card_brand?: string|null, idempotency_key: string} $valid */
        $valid = $this->validated();
        /** @var Folio $folio */
        $folio = $this->route('folio');

        return [
            'method' => $valid['method'], 'amount' => isset($valid['amount']) ? (int) $valid['amount'] : null, 'currency' => $folio->currency->value,
            'reference' => $valid['reference'] ?? null, 'card_last_four' => $valid['card_last_four'] ?? null, 'card_brand' => $valid['card_brand'] ?? null,
            'idempotency_key' => $valid['idempotency_key'],
        ];
    }
}
