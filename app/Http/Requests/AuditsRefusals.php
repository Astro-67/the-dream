<?php

namespace App\Http\Requests;

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Audit\Audit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;

trait AuditsRefusals
{
    protected function failedAuthorization(): void
    {
        $this->auditRefusal('authorization');
        parent::failedAuthorization();
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->auditRefusal('validation');
        parent::failedValidation($validator);
    }

    private function auditRefusal(string $stage): void
    {
        $subject = $this->route('reservation') ?? $this->route('guest') ?? $this->route('booker') ?? $this->route('room') ?? $this->route('assignment') ?? $this->route('folio') ?? $this->route('posting') ?? $this->route('feeProposal') ?? $this->route('cashierShift');
        if (! $subject instanceof Model) {
            $subject = match (true) {
                $this->routeIs('guests.*') => new Guest,
                $this->routeIs('bookers.*') => new Booker,
                $this->routeIs('housekeeping.*') => new Assignment,
                $this->routeIs('cashier-shifts.*') => new CashierShift,
                default => new Reservation,
            };
        }
        // Never include submitted values or document validation messages.
        Audit::record($this->user(), 'refused', $subject, 'Sensitive form refused', ['result' => 'refused', 'stage' => $stage, 'action' => $this->route()?->getName()]);
    }
}
