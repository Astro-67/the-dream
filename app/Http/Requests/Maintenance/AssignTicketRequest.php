<?php

namespace App\Http\Requests\Maintenance;

use App\Domains\Maintenance\Models\Ticket;
use App\Http\Requests\AuditsRefusals;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignTicketRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('assign', $ticket) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->route('ticket');

        return [
            'assignee_id' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($ticket) {
                $user = is_string($value) ? User::query()->find($value) : null;
                if ($user === null || ! $ticket->acceptsAssignee($user)) {
                    $fail(__('Choose a member with access to this property.'));
                }
            }],
        ];
    }
}
