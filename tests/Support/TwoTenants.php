<?php

namespace Tests\Support;

use App\Domains\Tenancy\Actions\AcceptInvitation;
use App\Domains\Tenancy\Actions\SendInvitation;
use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The isolation fixture (spec #1): two organizations, "Alpha" ($a) and "Bravo"
 * ($b), with two properties each and a member in every organization role.
 * Build it with twoTenants() in any feature test.
 */
final class TwoTenants
{
    public function __construct(
        public readonly TenantFixture $a,
        public readonly TenantFixture $b,
    ) {}

    public static function build(): self
    {
        return new self(TenantFixture::build('Alpha'), TenantFixture::build('Bravo'));
    }

    /**
     * A person who belongs to both organizations, having accepted a separate
     * invitation from each owner. A role that may hold all properties gets
     * them; any other role gets the organization's first property ($property).
     * An owner in either has two-factor authentication on, like the fixture's owners.
     */
    public function memberOfBoth(OrganizationRole $inAlpha, OrganizationRole $inBravo): User
    {
        $factory = in_array(OrganizationRole::Owner, [$inAlpha, $inBravo], true) ? User::factory()->withTwoFactor() : User::factory();
        $user = $factory->create(['name' => 'Dual Member', 'email' => 'dual.member@example.test']);

        foreach ([[$this->a, $inAlpha], [$this->b, $inBravo]] as [$tenant, $role]) {
            $grant = $role->mayHaveAllProperties()
                ? MembershipGrant::allProperties($role)
                : MembershipGrant::properties($role, [$tenant->property->id]);

            $invitation = inTenant($tenant->organization, fn () => app(SendInvitation::class)->handle(
                $tenant->owner,
                new InvitationDetails($user->email, $grant, CarbonImmutable::now()->addDays(InvitationDetails::DEFAULT_EXPIRY_DAYS)),
            ));

            app(AcceptInvitation::class)->handle($user, $invitation);
        }

        return $user->fresh() ?? $user;
    }
}
