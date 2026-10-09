<?php

namespace App\Http\Requests\Inventory;

use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An out-of-service period: taking a room out (store) or changing or ending a
 * period early (update). Dates are the property's; returns_on is the first
 * date back in service. A period that has started keeps its start date.
 */
class OutOfServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->period() ?? $this->route('room');

        return $record instanceof Room || $record instanceof OutOfServicePeriod
            ? $this->user()?->can('update', $record) ?? false
            : false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now(Tenancy::property()?->timezone)->toDateString();
        $period = $this->period();
        $started = $period !== null && $period->starts_on->toDateString() <= $today;

        return [
            'starts_on' => $started
                ? ['required', 'date_format:Y-m-d', 'in:'.$period->starts_on->toDateString()]
                : ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'returns_on' => ['required', 'date_format:Y-m-d', 'after:starts_on', 'after_or_equal:'.$today],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['starts_on.in' => __('This period has started, so its start date stays.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['starts_on' => __('out of service from'), 'returns_on' => __('back in service on')];
    }

    private function period(): ?OutOfServicePeriod
    {
        $period = $this->route('period');

        return $period instanceof OutOfServicePeriod ? $period : null;
    }
}
