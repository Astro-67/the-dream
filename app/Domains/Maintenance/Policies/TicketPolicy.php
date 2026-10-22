<?php

namespace App\Domains\Maintenance\Policies;

use App\Domains\Maintenance\Models\Ticket;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::inProperty($user, P::MaintenanceView);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return Access::allows($user, P::MaintenanceView, $ticket);
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, P::MaintenanceReport);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return Access::allows($user, P::MaintenanceManage, $ticket);
    }

    public function complete(User $user, Ticket $ticket): bool
    {
        return Access::allows($user, P::MaintenanceManage, $ticket)
            || ($ticket->assignee_id === $user->id && Access::allows($user, P::MaintenanceReport, $ticket));
    }
}
