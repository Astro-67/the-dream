<?php

namespace App\Http\Requests\Housekeeping;

use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;

class AssignmentDateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d']];
    }

    public function businessDate(): string
    {
        return $this->validated('date') ?? now(Tenancy::property()?->timezone)->toDateString();
    }
}
