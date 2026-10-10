<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRoomRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('assignRoom', $reservation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['room_id' => ['present', 'nullable', 'string', Rule::in(Room::query()->pluck('id')->all())]];
    }
}
