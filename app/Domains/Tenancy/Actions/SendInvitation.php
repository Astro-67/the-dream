<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Notifications\InvitationSent;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invites a person to the organization in the current tenant context and emails
 * them the link. The caller has authorized the grant (InvitationPolicy::create).
 */
class SendInvitation
{
    public function handle(User $inviter, InvitationDetails $details): Invitation
    {
        $organization = Tenancy::organization() ?? throw MissingTenantContext::forWrite(Invitation::class);
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($inviter, $details, $token) {
            $invitation = Invitation::query()->create([
                'email' => $details->email,
                'role' => $details->grant->role,
                'all_properties' => $details->grant->allProperties,
                'property_ids' => $details->grant->allProperties ? [] : $details->grant->propertyIds,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $inviter->getKey(),
                'expires_at' => $details->expiresAt,
            ]);

            Audit::record($inviter, 'invited', $invitation, 'Invitation sent', [
                'result' => 'success',
                'attributes' => [
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'all_properties' => $invitation->all_properties,
                    'property_ids' => $invitation->property_ids,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ],
            ]);

            return $invitation;
        });

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationSent($organization->name, $inviter->name, $invitation, $token));

        return $invitation;
    }
}
