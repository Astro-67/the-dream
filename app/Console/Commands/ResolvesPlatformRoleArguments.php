<?php

namespace App\Console\Commands;

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The `{email} {role}` arguments shared by the platform role commands.
 *
 * @mixin Command
 */
trait ResolvesPlatformRoleArguments
{
    /**
     * @return array{0: User, 1: PlatformRole}|null null after printing why
     */
    private function userAndRole(): ?array
    {
        $role = PlatformRole::tryFrom((string) $this->argument('role'));

        if ($role === null) {
            $roles = implode(', ', array_map(fn (PlatformRole $r) => $r->value, PlatformRole::cases()));
            $this->components->error("Unknown platform role. Use one of: {$roles}.");

            return null;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No user has that email address. They must register first.');

            return null;
        }

        return [$user, $role];
    }
}
