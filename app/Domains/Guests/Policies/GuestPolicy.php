<?php
namespace App\Domains\Guests\Policies;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
class GuestPolicy {
    public function viewAny(User $user): bool { return Tenancy::property() !== null && Access::allows($user, P::GuestsView, Tenancy::property()); }
    public function view(User $user, Model $record): bool { return Access::allows($user, P::GuestsView, $record); }
    public function create(User $user): bool { return Tenancy::property() !== null && Access::allows($user, P::GuestsRegister, Tenancy::property()); }
    public function update(User $user, Model $record): bool { return Access::allows($user, P::GuestsRegister, $record); }
}
