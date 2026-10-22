<?php

namespace App\Domains\Maintenance\Actions;

use App\Domains\Maintenance\Models\Ticket;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class ReportTicket
{
    /** @param  array{title: string, description: string, category: string, priority: string, room_id: string|null, location: string|null}  $details */
    public function handle(User $actor, array $details): Ticket
    {
        return DB::transaction(function () use ($actor, $details) {
            $ticket = Ticket::query()->create([...$details, 'reported_by' => $actor->id]);
            $ticket->recordEvent($actor, 'reported', null, $details);
            Audit::record($actor, 'reported', $ticket, 'Maintenance ticket reported');

            return $ticket;
        });
    }
}
