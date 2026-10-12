<?php

namespace App\Http\Requests\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['nullable', Rule::enum(HousekeepingStatus::class)], 'floor' => ['nullable', 'string', 'max:16']];
    }
}
