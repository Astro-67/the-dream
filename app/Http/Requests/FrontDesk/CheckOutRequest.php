<?php

namespace App\Http\Requests\FrontDesk;

use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('checkOut', $this->route('reservation')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
