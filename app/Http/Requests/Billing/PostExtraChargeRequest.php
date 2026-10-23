<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostExtraChargeRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('postCharge', $this->route('folio')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(PostExtraCharge::CATEGORIES)],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'unit_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array{category: string, description: string, quantity: int, unit_price: int, idempotency_key: string} */
    public function charge(): array
    {
        /** @var array{category: string, description: string, quantity: int|string, unit_price: int|string, idempotency_key: string} $valid */
        $valid = $this->validated();

        return [
            'category' => $valid['category'], 'description' => $valid['description'],
            'quantity' => (int) $valid['quantity'], 'unit_price' => (int) $valid['unit_price'], 'idempotency_key' => $valid['idempotency_key'],
        ];
    }
}
