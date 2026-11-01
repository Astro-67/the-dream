<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ZRA always adds the infrastructure levy on top of the room price, and the calculator now does too, so a levy
 * rule in force or scheduled as included no longer tells the truth. Ended versions keep their terms as the
 * record of how those nights were priced; posted charges keep their frozen tax lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tax_rules')->where('zra_tax_category', 'hotel_levy')->where('inclusive', true)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->subDay()->toDateString()))
            ->update(['inclusive' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Irreversible on purpose: an included levy is refused by TaxRule.
    }
};
