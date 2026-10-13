<?php

namespace App\Http\Requests\FrontDesk;

use App\Domains\Inventory\Models\Room;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomMoveRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('moveRoom', $this->route('reservation')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'string', Rule::in(Room::query()->pluck('id')->all())],
            'pricing' => ['sometimes', Rule::in(['keep', 'reprice'])],
        ];
    }
}
