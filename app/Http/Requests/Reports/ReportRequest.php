<?php

namespace App\Http\Requests\Reports;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reports\PropertyKpis;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', PropertyKpis::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (Tenancy::property() !== null) {
            $this->mergeIfMissing(['from' => BusinessDate::current(), 'to' => BusinessDate::current()]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
