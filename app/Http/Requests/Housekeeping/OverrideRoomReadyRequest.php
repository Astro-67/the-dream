<?php

namespace App\Http\Requests\Housekeeping;

use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Support\Audit\Audit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class OverrideRoomReadyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');
        if (! $room instanceof Room) {
            return false;
        }
        $allowed = $this->user()?->can('overrideReady', RoomStatus::query()->where('room_id', $room->id)->sole()) ?? false;
        if (! $allowed) {
            Audit::record($this->user(), 'ready_override_refused', $room, 'Room readiness override refused', ['result' => 'refused']);
        }

        return $allowed;
    }

    protected function failedValidation(Validator $validator): void
    {
        Audit::record($this->user(), 'ready_override_refused', $this->route('room'), 'Room readiness override refused', ['result' => 'refused', 'stage' => 'validation']);
        parent::failedValidation($validator);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
