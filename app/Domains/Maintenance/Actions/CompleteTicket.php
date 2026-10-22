<?php

namespace App\Domains\Maintenance\Actions;

use App\Domains\Maintenance\Models\Ticket;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a ticket done. Deliberately leaves room status and availability alone:
 * a repaired room is released by its out-of-service period, not by the ticket.
 */
class CompleteTicket
{
    public function handle(User $actor, Ticket $ticket): void
    {
        Audit::validationRefusal($actor, $ticket, 'Maintenance completion refused', fn () => DB::transaction(function () use ($actor, $ticket) {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->sole();
            if ($ticket->completed_at !== null) {
                return;
            }
            // Recheck on the locked row: a reassignment after authorization must stop the former assignee.
            if (! $actor->can('complete', $ticket)) {
                throw ValidationException::withMessages(['ticket' => __('This ticket is now assigned to someone else.')]);
            }

            $ticket->update(['completed_at' => now()]);
            $ticket->recordEvent($actor, 'completed', ['status' => 'open'], ['status' => 'done']);
            Audit::record($actor, 'completed', $ticket, 'Maintenance ticket completed');
        }));
    }
}
