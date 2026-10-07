<?php

/*
 * Mechanical coding rules from docs/architecture.md and docs/design.md, checked
 * by pattern so a breach fails CI instead of waiting for a reviewer. Each rule
 * names the one place the pattern is allowed.
 */

/**
 * @param  list<string>  $allowed  path fragments where the pattern is permitted
 * @return list<string>
 */
function sourceMatches(string $directory, string $extension, string $pattern, array $allowed = []): array
{
    $hits = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (! $file->isFile() || ! str_ends_with($path, $extension) || array_filter($allowed, fn (string $fragment) => str_contains($path, $fragment)) !== []) {
            continue;
        }

        foreach (file($path) as $number => $line) {
            if (preg_match($pattern, $line)) {
                $hits[] = substr($path, strlen(dirname(__DIR__, 2)) + 1).':'.($number + 1).'  '.trim($line);
            }
        }
    }

    return $hits;
}

test('role and permission checks go through Access (architecture §4.2)', function () {
    $hits = sourceMatches('app', '.php', '/->(hasRole|hasAnyRole|checkPermissionTo|hasPermissionTo)\s*\(/', ['/Support/Tenancy/']);

    expect($hits)->toBeEmpty("Use App\\Support\\Tenancy\\Access::allows() or Access::isOwner():\n".implode("\n", $hits));
});

test('active properties are selected with Property::active(), not by hand', function () {
    $hits = sourceMatches('app', '.php', "/whereNull\\(\\s*'archived_at'/", ['/Domains/Tenancy/Models/Property.php']);

    expect($hits)->toBeEmpty("Use the Property::active() scope:\n".implode("\n", $hits));
});

test('flash messages are translatable (design.md: English and Kiswahili)', function () {
    $hits = sourceMatches('app', '.php', "/'message'\\s*=>\\s*['\"]/");

    expect($hits)->toBeEmpty("Wrap toast messages in __():\n".implode("\n", $hits));
});

test('tenant screens colour status with --status-* tokens, not the raw palette (design.md)', function () {
    $palette = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone';
    $hits = [];

    // The starter-kit screens predate the tokens; everything built on Tenancy uses them.
    foreach (['resources/js/features', 'resources/js/components/shared', 'resources/js/pages/invitations', 'resources/js/pages/members', 'resources/js/pages/organizations', 'resources/js/pages/platform', 'resources/js/pages/properties'] as $directory) {
        $hits = [...$hits, ...sourceMatches($directory, '.tsx', "/\\b(text|bg|border|ring|fill|stroke|outline|divide|decoration)-({$palette})-\\d{2,3}\\b/")];
    }

    expect($hits)->toBeEmpty("Use a --status-* token from resources/css/app.css:\n".implode("\n", $hits));
});
