<?php

namespace App\Http\Requests\FrontDesk;

use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
{
    use AuditsRefusals;

    /** A reason asks to override an open balance, which needs its own permission. */
    public function authorize(): bool
    {
        return $this->user()?->can($this->filled('override_reason') ? 'checkOutWithBalance' : 'checkOut', $this->route('reservation')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['override_reason' => ['nullable', 'string', 'max:255']];
    }
}
