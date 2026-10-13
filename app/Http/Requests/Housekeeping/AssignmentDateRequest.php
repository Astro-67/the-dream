<?php

namespace App\Http\Requests\Housekeeping;

use App\Domains\FrontDesk\BusinessDate;
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
        return $this->validated('date') ?? BusinessDate::current();
    }
}
