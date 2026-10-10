<?php

namespace App\Http\Requests\Guests;

use App\Domains\Guests\Models\Guest;
use Illuminate\Foundation\Http\FormRequest;

class GuestMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Guest::class) ?? false;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'document_number' => ['nullable', 'string', 'max:255'],
        ];
    }
}
