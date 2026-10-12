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

        return $this->user()?->can('update', RoomStatus::query()->where('room_id', $room->id)->sole()) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(HousekeepingStatus::class)], 'reason' => ['required', 'string', 'max:1000']];
    }
}
