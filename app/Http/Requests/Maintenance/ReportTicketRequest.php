<?php

namespace App\Http\Requests\Maintenance;

use App\Domains\Inventory\Models\Room;
use App\Domains\Maintenance\Enums\TicketCategory;
use App\Domains\Maintenance\Enums\TicketPriority;
use App\Domains\Maintenance\Models\Ticket;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportTicketRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            // Rooms are scoped to the intended property, so a foreign room ID is not in the list.
            'room_id' => ['nullable', 'string', Rule::in(Room::query()->active()->pluck('id')->all())],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
