<?php

use App\Domains\Platform\Models\PlatformRoleAssignment;

arch('platform roles are granted only through the Platform domain, never by organization membership or invitations')
    ->expect(PlatformRoleAssignment::class)
    ->toOnlyBeUsedIn(['App\Domains\Platform', 'App\Models\User']);
