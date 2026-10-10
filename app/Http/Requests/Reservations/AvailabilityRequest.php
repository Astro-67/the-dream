<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Inventory\Models\RoomType;
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
        return [
            'arrival' => ['required_with:departure,adults,children,room_type_id', 'date_format:Y-m-d'],
            'departure' => ['required_with:arrival', 'date_format:Y-m-d', 'after:arrival'],
            'adults' => ['required_with:arrival', 'integer', 'between:1,20'],
            'children' => ['required_with:arrival', 'integer', 'between:0,20'],
            'room_type_id' => ['nullable', 'string', Rule::in(RoomType::query()->pluck('id')->all())],
        ];
    }
}
