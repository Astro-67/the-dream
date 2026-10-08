<?php

test('only integration domains may record outbox messages', function () {
    $violations = [];
    $application = dirname(__DIR__, 2).'/app';
    $allowed = ['/Domains/Distribution/', '/Domains/ZanReporting/', '/Domains/FiscalReceipting/'];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($application)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (! str_contains($path, '/Support/Outbox/')) {
            $source = file_get_contents($path);
            $usesOutbox = str_contains($source, 'App\\Support\\Outbox\\Outbox')
                || preg_match('/Outbox::class/', $source)
                || preg_match('/Outbox\s+\$outbox/', $source);
            if ($usesOutbox && preg_match('/->record\s*\(/', $source)
                && ! array_any($allowed, fn (string $directory) => str_contains($path, $directory))) {
                $violations[] = $path.' records outbox messages outside an integration domain';
            }
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});
