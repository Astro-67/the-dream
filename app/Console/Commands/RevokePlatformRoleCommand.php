<?php

namespace App\Console\Commands;

use App\Domains\Platform\Actions\RevokePlatformRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Takes a platform role away from the command line. Recorded in the platform activity log.
 */
#[Signature('platform:revoke-role {email : Email address of the user} {role : platform_administrator or compliance_operator}')]
#[Description('Take a platform role away from a user')]
class RevokePlatformRoleCommand extends Command
{
    use ResolvesPlatformRoleArguments;

    public function handle(RevokePlatformRole $revoke): int
    {
        $target = $this->userAndRole();

        if ($target === null) {
            return self::FAILURE;
        }

        [$user, $role] = $target;
        $revoke->handle($user, $role);
        $this->components->info("{$user->email} is no longer a {$role->label()}.");

        return self::SUCCESS;
    }
}
