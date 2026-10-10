<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Inventory\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', RoomType::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $arrival = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $this->string('arrival')->value()) ?: null, report: false);

        return [
            'arrival' => ['required_with:departure,adults,children,room_type_id', 'date_format:Y-m-d'],
            'departure' => ['required_with:arrival', 'date_format:Y-m-d', 'after:arrival', ...($arrival ? ['before_or_equal:'.$arrival->addDays(365)->toDateString()] : [])],
            'adults' => ['required_with:arrival', 'integer', 'between:1,20'],
            'children' => ['required_with:arrival', 'integer', 'between:0,20'],
            'room_type_id' => ['nullable', 'string', Rule::in(RoomType::query()->pluck('id')->all())],
        ];
    }
}
