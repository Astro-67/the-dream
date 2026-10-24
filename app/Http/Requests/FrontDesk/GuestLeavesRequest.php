<?php

namespace App\Http\Requests\FrontDesk;

use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class GuestLeavesRequest extends FormRequest
{
    use AuditsRefusals;

    /** Whoever may check the stay out may record one guest leaving it. */
    public function authorize(): bool
    {
        return $this->user()?->can('checkOut', $this->route('reservation')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['guest_id' => ['required', 'string', 'ulid']];
    }
}
