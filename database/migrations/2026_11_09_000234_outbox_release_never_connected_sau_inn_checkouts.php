<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 2026-11-09 Sau Inn checkouts (KHAMIS DUNIA, ASHA MOHAMMED HAJI) hit the 10 s connect timeout, so ZRA never got them;
        // the hotel confirmed both still need filing. The status guard keeps this a no-op anywhere else or if already resolved.
        DB::table('outbox_messages')
            ->whereIn('id', ['01m6zggy8ft8ar168sn3mqy9gv', '01m6zgpywbz4petgdg36afd0zd'])
            ->where('integration', 'vfms')->where('event', 'checkout')
            ->where('status', 'unknown')->where('attempt_count', 1)
            ->whereNull('claim_token')
            ->update(['status' => 'retryable', 'next_attempt_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: once re-sent, the filings' outcome is ZRA's answer.
    }
};
