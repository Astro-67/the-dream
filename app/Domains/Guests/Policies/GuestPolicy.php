<?php

namespace App\Domains\Guests\Policies;

use App\Domains\Guests\Models\Guest;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use Illuminate\Database\Eloquent\Model;

class GuestPolicy
{
    public function viewIdentity(User $user, Model $record): bool
    {
        return Access::allows($user, P::GuestIdentityView, $record);
    }

    public function viewAny(User $user): bool
    {
        return Access::inProperty($user, P::GuestsView);
    }

    public function view(User $user, Model $record): bool
    {
        return Access::allows($user, P::GuestsView, $record);
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, P::GuestsRegister);
    }

    public function merge(User $user, Guest $record): bool
    {
        return Access::allows($user, P::GuestsMerge, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return Access::allows($user, P::GuestsRegister, $record);
    }
}
