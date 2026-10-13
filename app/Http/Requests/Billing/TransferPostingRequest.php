<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\Folio;

class TransferPostingRequest extends ReversePostingRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transfer', $this->folio()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['folio_id' => ['required', 'string', 'max:26'], 'reason' => ['required', 'string', 'max:255']];
    }

    /** The target folio; another tenant's folio is out of scope and refused like a wrong folio. */
    public function target(): Folio
    {
        return Folio::query()->find($this->validated('folio_id')) ?? new Folio;
    }
}
