<?php

namespace App\Http\Requests\Reports;

use App\Domains\Reports\Collections;
use App\Support\Tenancy\Tenancy;
use Illuminate\Validation\Rule;

class PaymentsRequest extends ReportRequest
{
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['period' => 'today', 'view' => 'received']);
        $property = Tenancy::property();
        $period = $this->input('period');
        if ($property !== null && is_string($period) && $period !== 'custom') {
            $this->merge(app(Collections::class)->range($property, $period));
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'period' => ['required', Rule::in(['today', 'this-month', 'last-month', 'this-year', 'custom'])],
            'view' => ['required', Rule::in(['received', 'outstanding'])],
        ];
    }
}
