<?php

use App\Support\Tenancy\TenancyManager;
use App\Support\Tenancy\TenantScope;

arch('tenant scoping can only be removed inside the Tenancy module')
    ->expect(TenantScope::class)
    ->toOnlyBeUsedIn('App\Support\Tenancy');

arch('code enters a tenant through the Tenancy facade, not the manager')
    ->expect(TenancyManager::class)
    ->toOnlyBeUsedIn(['App\Support\Tenancy', 'App\Providers']);

test('tenant bypass calls are limited to tenancy or platform code', function () {
    $violations = [];
    $tenantTables = ['organizations', 'properties', 'organization_memberships', 'property_memberships', 'invitations'];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app')) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/Support/Tenancy/') || str_contains($path, '/Domains/Platform/')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (preg_match('/withoutGlobalScopes?\s*\(/', $source)) {
            $violations[] = $path.' calls withoutGlobalScope(s) outside tenancy/platform code';
        }

        if (preg_match('/DB::table\s*\(/', $source) && preg_match('/'.implode('|', $tenantTables).'/', $source)) {
            $violations[] = $path.' uses DB::table against a tenant table outside tenancy/platform code';
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});
