<?php

namespace App\Http\Requests\Inventory;

use App\Domains\Inventory\Models\RoomType;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The room type form, for adding a room type (no route room type) or editing one.
 */
class RoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roomType = $this->route('roomType');

        return $roomType instanceof RoomType
            ? $this->user()?->can('update', $roomType) ?? false
            : $this->user()?->can('create', RoomType::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $roomType = $this->route('roomType');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16', Rule::unique('room_types', 'code')
                ->where('property_id', Tenancy::current()?->propertyId)
                ->ignore($roomType instanceof RoomType ? $roomType->id : null)],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_occupancy' => ['required', 'integer', 'min:1', 'lte:max_occupancy'],
            'max_adults' => ['required', 'integer', 'min:1', 'lte:max_occupancy'],
            'max_children' => ['required', 'integer', 'min:0', 'lte:max_occupancy'],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'base_occupancy' => 'base occupancy',
            'max_adults' => 'maximum adults',
            'max_children' => 'maximum children',
            'max_occupancy' => 'maximum guests',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->safe()->only(RoomType::FIELDS);
    }
}
