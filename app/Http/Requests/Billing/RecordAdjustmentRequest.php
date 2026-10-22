<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Actions\RecordPayment;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAdjustmentRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can($this->routeIs('folios.refunds.store') ? 'refund' : 'writeOff', $this->route('folio')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'reason' => ['required', 'string', 'max:255'],
            'method' => $this->routeIs('folios.refunds.store') ? ['required', Rule::in(RecordPayment::METHODS)] : ['exclude'],
            'reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'payment_posting_id' => $this->routeIs('folios.refunds.store') ? ['nullable', 'string', 'max:26'] : ['exclude'],
        ];
    }

    /** @return array{amount: int, reason: string, idempotency_key: string, method: string|null, reference: string|null, payment_posting_id: string|null} */
    public function adjustment(): array
    {
        return [
            'amount' => $this->integer('amount'), 'reason' => $this->string('reason')->toString(),
            'idempotency_key' => $this->string('idempotency_key')->toString(),
            'method' => $this->routeIs('folios.refunds.store') ? $this->string('method')->toString() : null,
            'reference' => $this->filled('reference') ? $this->string('reference')->toString() : null,
            'payment_posting_id' => $this->routeIs('folios.refunds.store') && $this->filled('payment_posting_id') ? $this->string('payment_posting_id')->toString() : null,
        ];
    }
}
