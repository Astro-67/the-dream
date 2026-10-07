<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $ownedMemberships = Tenancy::within(TenantContext::platform('Check last-owner account deletion'), fn () => OrganizationMembership::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->get()
                ->filter(fn (OrganizationMembership $membership) => Tenancy::within(TenantContext::forOrganization($membership->organization_id), fn () => $membership->user->hasRole(OrganizationRole::Owner->value))));

            foreach ($ownedMemberships as $membership) {
                $hasAnotherOwner = Tenancy::within(TenantContext::forOrganization($membership->organization_id), fn () => OrganizationMembership::query()
                    ->where('user_id', '!=', $user->getKey())
                    ->lockForUpdate()
                    ->get()
                    ->contains(fn (OrganizationMembership $other) => $other->user->hasRole(OrganizationRole::Owner->value)));

                if (! $hasAnotherOwner) {
                    throw ValidationException::withMessages([
                        'account' => __('Your account cannot be deleted while you are the last owner of an organization. Transfer ownership or add another owner first.'),
                    ]);
                }
            }

            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
