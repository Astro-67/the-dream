<?php

namespace App\Domains\Maintenance\Actions;

use App\Domains\Maintenance\Models\Ticket;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignTicket
{
    public function handle(User $actor, Ticket $ticket, string $assigneeId): void
    {
        Audit::validationRefusal($actor, $ticket, 'Maintenance assignment refused', fn () => DB::transaction(function () use ($actor, $ticket, $assigneeId) {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->sole();
            if ($ticket->completed_at !== null) {
                throw ValidationException::withMessages(['assignee_id' => __('This ticket is done and can no longer be assigned.')]);
            }
            $before = $ticket->assignee_id;
            if ($before === $assigneeId) {
                return;
            }

            $ticket->update(['assignee_id' => $assigneeId]);
            $event = $before === null ? 'assigned' : 'reassigned';
            $ticket->recordEvent($actor, $event, ['assignee_id' => $before], ['assignee_id' => $assigneeId]);
            Audit::record($actor, $event, $ticket, 'Maintenance ticket '.$event, ['old' => ['assignee_id' => $before], 'attributes' => ['assignee_id' => $assigneeId]]);
        }));
    }
}
