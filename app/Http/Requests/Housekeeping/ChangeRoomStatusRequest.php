<?php

namespace App\Http\Requests\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeRoomStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');
        if (! $room instanceof Room) {
            return false;
        }

        $status = RoomStatus::query()->where('room_id', $room->id)->sole();

        return $status->status === HousekeepingStatus::InspectionRequired
            ? ($this->user()?->can('supervise', RoomStatus::class) ?? false)
            : ($this->user()?->can('update', $status) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([...array_column(HousekeepingStatus::cases(), 'value'), 'cleaned'])],
            'reason' => ['required_unless:status,cleaned', 'nullable', 'string', 'max:1000'],
        ];
    }
}
