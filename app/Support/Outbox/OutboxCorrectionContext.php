<?php

namespace App\Support\Outbox;

use Closure;
use Illuminate\Support\Facades\Context;

final class OutboxCorrectionContext
{
    private const KEY = 'outbox-correction';

    public function active(): bool
    {
        return Context::get(self::KEY, false);
    }

    public function run(Closure $callback): mixed
    {
        $previous = $this->active();
        Context::add(self::KEY, true);
        try {
            return $callback();
        } finally {
            Context::add(self::KEY, $previous);
        }
    }
}
