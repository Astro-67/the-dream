<?php

namespace App\Http\Requests\Inventory;

use App\Domains\Inventory\Models\Room;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The room form: adding a room (with its room type), editing one (number and
 * floor only), or moving one to another room type (room type only).
 */
class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            ? $this->user()?->can('update', $room) ?? false
            : $this->user()?->can('create', Room::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $room = $this->route('room');

        if ($this->routeIs('rooms.move')) {
            return ['room_type_id' => ['required', 'string', self::roomTypeOfThisProperty()]];
        }

        return [
            'number' => ['required', 'string', 'max:16', Rule::unique('rooms', 'number')
                ->where('property_id', Tenancy::current()?->propertyId)
                ->ignore($room instanceof Room ? $room->id : null)],
            'floor' => ['nullable', 'string', 'max:16'],
            ...($room instanceof Room ? [] : ['room_type_id' => ['required', 'string', self::roomTypeOfThisProperty()]]),
        ];
    }

    private static function roomTypeOfThisProperty(): Exists
    {
        return Rule::exists('room_types', 'id')->where('property_id', Tenancy::current()?->propertyId);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['room_type_id' => __('room type')];
    }
}
