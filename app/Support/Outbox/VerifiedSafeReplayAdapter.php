<?php

namespace App\Support\Outbox;

interface VerifiedSafeReplayAdapter extends DeliveryAdapter
{
    /** Provider contract version or evidence identifier for safe unchanged replay. */
    public function safeReplayContract(): string;
}
