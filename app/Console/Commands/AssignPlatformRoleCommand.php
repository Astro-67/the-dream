<?php

namespace App\Console\Commands;

use App\Domains\Platform\Actions\AssignPlatformRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Grants a platform role from the command line, for the first platform
 * administrator and for recovery. Recorded in the platform activity log.
 */
#[Signature('platform:assign-role {email : Email address of an existing user} {role : platform_administrator or compliance_operator}')]
#[Description('Give an existing user a platform role')]
class AssignPlatformRoleCommand extends Command
{
    use ResolvesPlatformRoleArguments;

    public function handle(AssignPlatformRole $assign): int
    {
        $target = $this->userAndRole();

        if ($target === null) {
            return self::FAILURE;
        }

        [$user, $role] = $target;
        $assign->handle($user, $role);
        $this->components->info("{$user->email} is now a {$role->label()}.");

        return self::SUCCESS;
    }
}
