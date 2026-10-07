<?php

namespace App\Support\Jobs;

/**
 * Marks a queued job that acts for one tenant (ADR 0008, ADR 0012).
 *
 * Every queued job, mail, notification and listener captures the tenant
 * context it is dispatched from and runs inside it on the worker (see
 * CallQueuedTenantHandler). A TenantAware job additionally refuses to run
 * without one: dispatched outside a tenant (or from the platform context), it
 * fails with MissingTenantContext instead of running unscoped.
 *
 * Dispatch it inside Tenancy::within(). Note that `fn () => Job::dispatch()`
 * returns the pending dispatch out of the closure, so the job is only queued
 * after the context has ended: use a block closure instead.
 */
interface TenantAware {}
