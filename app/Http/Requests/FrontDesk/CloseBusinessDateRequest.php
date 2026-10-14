<?php

namespace App\Http\Requests\FrontDesk;

use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class CloseBusinessDateRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('create', BusinessDateClose::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['business_date' => ['required', 'date_format:Y-m-d']];
    }
}
