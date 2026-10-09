<?php

use App\Domains\Tenancy\Enums\JurisdictionProfile;
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

test('only JurisdictionRules names a jurisdiction profile case (ADR 0014)', function () {
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app')) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (! $file->isFile() || $file->getExtension() !== 'php' || preg_match('#/Domains/Tenancy/(Jurisdiction/|Enums/JurisdictionProfile\.php$)#', $path)) {
            continue;
        }

        if (namesJurisdictionCase((string) file_get_contents($file->getPathname()))) {
            $violations[] = $path.' names a jurisdiction profile case; ask JurisdictionRules instead';
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

function namesJurisdictionCase(string $source): bool
{
    preg_match_all('/\bJurisdictionProfile\s+as\s+(\w+)/i', $source, $aliases);
    foreach ($aliases[1] as $alias) {
        $source = preg_replace('/\b'.preg_quote($alias, '/').'\s*::/', 'JurisdictionProfile::', $source);
    }
    $names = array_map(fn (JurisdictionProfile $profile) => preg_quote($profile->name, '/'), JurisdictionProfile::cases());
    $values = array_map(fn (JurisdictionProfile $profile) => preg_quote($profile->value, '/'), JurisdictionProfile::cases());
    $comparison = '/JurisdictionProfile::('.implode('|', $names).')\b|[\'"]('.implode('|', $values).')[\'"]/';

    return preg_match($comparison, $source) === 1;
}

test('the jurisdiction case guard detects aliased imports', function () {
    expect(namesJurisdictionCase('<?php use App\Domains\Tenancy\Enums\JurisdictionProfile as Profile; $profile === Profile::Zanzibar;'))->toBeTrue();
});
