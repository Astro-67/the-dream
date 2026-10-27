<?php

namespace App\Http\Requests\Reports;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reports\PropertyKpis;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', PropertyKpis::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (Tenancy::property() !== null) {
            $this->mergeIfMissing(['from' => BusinessDate::current(), 'to' => BusinessDate::current(), 'view' => 'overview']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['bail', 'required', 'date_format:Y-m-d'],
            'to' => ['bail', 'required', 'date_format:Y-m-d', Rule::when(is_string($this->input('from')), 'after_or_equal:from')],
            'settled' => ['sometimes', 'boolean'],
            'view' => ['sometimes', Rule::in(['overview', 'filings'])],
        ];
    }
}
