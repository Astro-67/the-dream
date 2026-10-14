<?php

namespace Tests\Support;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ParallelBookings
{
    /** @return list<array<string, mixed>> */
    public static function run(string $input, string $workerFile = 'booking-worker.php'): array
    {
        $directory = sys_get_temp_dir().'/booking-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/'.$workerFile), $input, "$directory/ready-$i", "$directory/start"], base_path(), timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! file_exists("$directory/ready-0") || ! file_exists("$directory/ready-1")) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Workers failed to reach booking barrier.');
                }
                usleep(10000);
            }
            touch("$directory/start");
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
                try {
                    $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException($worker->getOutput().$worker->getErrorOutput(), previous: $exception);
                }
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob("$directory/*") as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
