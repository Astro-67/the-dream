<?php

namespace App\Http\Requests;

use App\Domains\Billing\Models\ApprovalThreshold;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Maintenance\Models\Ticket;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Subscriptions\Models\BillingDetails;
use App\Domains\Subscriptions\Models\Plan;
use App\Domains\Tenancy\Models\Property;
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
        $subject = $this->route('reservation') ?? $this->route('guest') ?? $this->route('booker') ?? $this->route('room') ?? $this->route('assignment') ?? $this->route('ticket') ?? $this->route('folio') ?? $this->route('posting') ?? $this->route('feeProposal') ?? $this->route('cashierShift') ?? $this->route('approval') ?? $this->route('noShowProposal') ?? $this->route('importJob') ?? $this->route('property') ?? $this->route('plan') ?? $this->route('invoice');
        if (! $subject instanceof Model) {
            $subject = match (true) {
                $this->routeIs('guests.*') => new Guest,
                $this->routeIs('bookers.*') => new Booker,
                $this->routeIs('housekeeping.*') => new Assignment,
                $this->routeIs('maintenance.*') => new Ticket,
                $this->routeIs('cashier-shifts.*') => new CashierShift,
                $this->routeIs('approvals.*') => new PostingApproval,
                $this->routeIs('approval-thresholds.*') => new ApprovalThreshold,
                $this->routeIs('night-audit.*') => new BusinessDateClose,
                $this->routeIs('imports.*') => new ImportJob,
                $this->routeIs('properties.*') => new Property,
                $this->routeIs('platform.properties.decline-go-live') => new Property,
                $this->routeIs('platform.plans.*') => new Plan,
                $this->routeIs('platform.billing-details.*') => new BillingDetails,
                default => new Reservation,
            };
        }
        // Never include submitted values or document validation messages.
        Audit::record($this->user(), 'refused', $subject, 'Sensitive form refused', ['result' => 'refused', 'stage' => $stage, 'action' => $this->route()?->getName()]);
    }
}
