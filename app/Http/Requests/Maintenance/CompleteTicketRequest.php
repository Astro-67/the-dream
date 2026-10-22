<?php

namespace App\Http\Requests\Maintenance;

use App\Domains\Maintenance\Models\Ticket;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class CompleteTicketRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('complete', $ticket) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
