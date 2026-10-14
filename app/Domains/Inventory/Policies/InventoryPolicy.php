<?php

namespace App\Domains\Inventory\Policies;

use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;

/**
 * A property's room types and rooms.
 *
 * - reservations.view (front desk, auditors and up): see them.
 * - inventory.manage (owner, group operations manager, property manager): change them.
 *
 * Class-level checks are judged in the current property.
 */
class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::ReservationsView, Tenancy::property());
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, OrganizationPermission::InventoryManage);
    }

    /**
     * A deactivated room is kept for history only: it is never changed again.
     */
    public function update(User $user, Model $record): bool
    {
        return ! ($record instanceof Room && $record->deactivated_at !== null)
            && Access::allows($user, OrganizationPermission::InventoryManage, $record);
    }
}
