<?php

use Symfony\Component\Process\Process;

test('fiscal document rendering preserves per-document modes and correction annotations', function () {
    $process = new Process(['bun', 'tests/Frontend/CheckoutReportCheck.tsx'], dirname(__DIR__, 2));
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
