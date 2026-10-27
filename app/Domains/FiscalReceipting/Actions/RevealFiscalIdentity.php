<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class RevealFiscalIdentity
{
    /** @return array<string, mixed> */
    public function handle(User $actor, Reservation $reservation): array
    {
        return DB::transaction(function () use ($actor, $reservation) {
            $report = CheckoutReport::of($reservation, viewIdentity: true, revealIdentity: true);
            Audit::record($actor, 'fiscal_identity_revealed', $reservation, 'Fiscal report identity revealed');

            return $report;
        });
    }
}
