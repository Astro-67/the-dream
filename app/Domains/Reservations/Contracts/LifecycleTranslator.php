<?php

namespace App\Domains\Reservations\Contracts;

use App\Domains\Reservations\Models\ReservationLifecycleEvent;

/**
 * Turns a recorded lifecycle event into an integration's outbox messages (ADR 0013).
 * RecordLifecycleEvent hands every live event to each translator tagged
 * LifecycleTranslator::TAG, inside the reservation's transaction and lock, so
 * core actions never know which integrations exist. Bind one per integration:
 *
 *     $this->app->tag([VfmsTranslator::class], LifecycleTranslator::TAG);
 */
interface LifecycleTranslator
{
    public const TAG = 'reservations.lifecycle-translators';

    public function translate(ReservationLifecycleEvent $event): void;
}
