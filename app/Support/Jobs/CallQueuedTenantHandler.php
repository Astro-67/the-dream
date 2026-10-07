<?php

namespace App\Support\Jobs;

use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;

/**
 * Carries the tenant context through the queue (ADR 0008, ADR 0012).
 *
 * capture() is a queue payload hook: when a job, mail, notification or listener
 * is queued inside Tenancy::within(), the context's IDs go into the payload and
 * this handler takes over from Laravel's. On the worker, it re-enters that
 * context through Tenancy::within() before the job is even unserialized, so the
 * job's models are restored, its middleware and handle() run, and the next job
 * of a chain is dispatched, all inside the tenant, with spatie's team ID set.
 * The worker's previous state is restored afterwards, so the next job starts clean.
 *
 * A TenantAware job without a captured tenant fails straight away (no retries)
 * instead of running unscoped.
 */
final class CallQueuedTenantHandler extends CallQueuedHandler
{
    private const string PAYLOAD_KEY = 'tenancy';

    /**
     * Registered with Queue::createPayloadUsing(). Runs as the job is queued.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function capture(?string $connection, ?string $queue, array $payload): array
    {
        $command = data_get($payload, 'data.command');

        if (! is_object($command) || ($payload['job'] ?? null) !== CallQueuedHandler::class.'@call') {
            return [];
        }

        $context = Tenancy::current();

        if ($context === null && ! $command instanceof TenantAware) {
            return [];
        }

        return [
            'job' => self::class.'@call',
            self::PAYLOAD_KEY => $context === null ? null : [
                'organization_id' => $context->organizationId,
                'property_id' => $context->propertyId,
                'platform_reason' => $context->platformReason,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function call(Job $job, array $data): void
    {
        $payload = $job->payload();
        $context = self::capturedContext($payload);

        if (is_a((string) data_get($payload, 'data.commandName'), TenantAware::class, true)
            && ($context === null || $context->isPlatform())) {
            $exception = MissingTenantContext::forJob($job->resolveName());
            $job->fail($exception);

            throw $exception;
        }

        $this->within($context, function () use ($job, $data): void {
            parent::call($job, $data);
        });
    }

    /**
     * The job's failed() hook runs in its tenant too.
     *
     * @param  array<string, mixed>  $data
     * @param  \Throwable|null  $e
     */
    public function failed(array $data, $e, string $uuid, ?Job $job = null): void
    {
        $context = $job === null ? null : self::capturedContext($job->payload());

        $this->within($context, function () use ($data, $e, $uuid, $job): void {
            parent::failed($data, $e, $uuid, $job);
        });
    }

    /**
     * @param  Closure(): void  $callback
     */
    private function within(?TenantContext $context, Closure $callback): void
    {
        if ($context === null) {
            $callback();

            return;
        }

        Tenancy::within($context, $callback);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function capturedContext(array $payload): ?TenantContext
    {
        $captured = $payload[self::PAYLOAD_KEY] ?? null;

        if (! is_array($captured)) {
            return null;
        }

        if (is_string($captured['platform_reason'] ?? null)) {
            return TenantContext::platform($captured['platform_reason']);
        }

        $propertyId = $captured['property_id'] ?? null;

        return TenantContext::fromIds((string) ($captured['organization_id'] ?? ''), is_string($propertyId) ? $propertyId : null);
    }
}
