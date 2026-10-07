<?php

namespace App\Support\Tenancy;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Which queued job or console command is running, so that entering the
 * platform context can be logged with it when there is no signed-in actor.
 *
 * @internal
 */
final class Origin
{
    /**
     * @var list<array{kind: 'job'|'command', name: string}>
     */
    private array $running = [];

    public function listen(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->enter('job', $event->job->resolveName()));
        $events->listen(JobAttempted::class, fn (JobAttempted $event) => $this->leave('job', $event->job->resolveName()));
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->enter('command', (string) $event->command));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => $this->leave('command', (string) $event->command));
    }

    /**
     * The innermost running job or command, as activity log properties.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $current = $this->running === [] ? null : $this->running[array_key_last($this->running)];

        return $current === null ? [] : [$current['kind'] => $current['name']];
    }

    /**
     * @param  'job'|'command'  $kind
     */
    private function enter(string $kind, string $name): void
    {
        $this->running[] = ['kind' => $kind, 'name' => $name];
    }

    /**
     * @param  'job'|'command'  $kind
     */
    private function leave(string $kind, string $name): void
    {
        if ($this->running !== [] && $this->running[array_key_last($this->running)] === ['kind' => $kind, 'name' => $name]) {
            array_pop($this->running);
        }
    }
}
