<?php

use App\Support\Tenancy\TenancyManager;
use App\Support\Tenancy\TenantScope;

arch('tenant scoping can only be removed inside the Tenancy module')
    ->expect(TenantScope::class)
    ->toOnlyBeUsedIn('App\Support\Tenancy');

arch('code enters a tenant through the Tenancy facade, not the manager')
    ->expect(TenancyManager::class)
    ->toOnlyBeUsedIn(['App\Support\Tenancy', 'App\Providers']);
