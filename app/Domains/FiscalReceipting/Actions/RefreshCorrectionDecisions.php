<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\HttpVfms;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Jobs\ReadCorrectionDecisions;
use App\Domains\FiscalReceipting\Models\CorrectionDecision;
use App\Domains\FiscalReceipting\Models\CorrectionDecisionRead;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxRecovery;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * On-demand section 2.13 refresh (spec #228): one queued read per property at a time, at most one
 * new read a minute. Only an exact old/new/unit match records a decision or reconciles an uncertain
 * ERROR_SAVE; absence never means Rejected, and nothing is ever resubmitted.
 */
class RefreshCorrectionDecisions
{
    /** ZRA's documented decisions, in the order they may progress; approved and rejected are final. */
    public const DECISIONS = ['Pending' => 'pending', 'Verification' => 'verification', 'Approved' => 'approved', 'Rejected' => 'rejected'];

    private const RANK = ['pending' => 0, 'verification' => 1, 'approved' => 2, 'rejected' => 2];

    private const SENT = ['delivered', 'unknown', 'exhausted_unknown'];

    /** A read whose worker died stops blocking refreshes after this long. */
    private const STALE_MINUTES = 10;

    public function __construct(private HttpVfms $http) {}

    /** @return array{refreshing: bool, observedAt: string|null} */
    public static function status(Property $property): array
    {
        $reads = fn () => CorrectionDecisionRead::query()->where('property_id', $property->id);

        return [
            'refreshing' => $reads()->whereNull('outcome')->where('created_at', '>', now()->subMinutes(self::STALE_MINUTES))->exists(),
            'observedAt' => $reads()->where('outcome', 'observed')->latest('finished_at')->first()?->finished_at?->toIso8601String(),
        ];
    }

    public function handle(User $actor, DocumentCorrection $correction): CorrectionDecisionRead
    {
        return Audit::validationRefusal($actor, $correction, 'ZRA correction status refresh refused', function () use ($actor, $correction): CorrectionDecisionRead {
            $authorization = Gate::forUser($actor)->inspect('refresh', $correction);
            if ($authorization->denied()) {
                Audit::record($actor, 'refused', $correction, 'ZRA correction status refresh refused', ['result' => 'refused', 'stage' => 'authorization']);
            }
            $authorization->authorize();
            try {
                $read = DB::transaction(function () use ($actor, $correction): CorrectionDecisionRead {
                    $property = Property::query()->whereKey($correction->property_id)->lockForUpdate()->firstOrFail();
                    $this->assertRefreshable($property);
                    $message = OutboxMessage::query()->where('property_id', $property->id)->findOrFail($correction->outbox_message_id);
                    if (! in_array($message->status, self::SENT, true)) {
                        throw new RuntimeException('The correction must reach ZRA before its status can be refreshed.');
                    }
                    if (self::status($property)['refreshing']) {
                        throw new RuntimeException('A ZRA status refresh is already in progress.');
                    }
                    $key = 'zra-correction-refresh:'.$property->id;
                    if (RateLimiter::tooManyAttempts($key, 1)) {
                        throw new RuntimeException(__('ZRA status was refreshed less than a minute ago. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]));
                    }
                    RateLimiter::hit($key, 60);
                    $submitted = DocumentCorrection::query()->where('property_id', $property->id)->min('created_at');
                    $local = fn ($time) => now()->parse($time)->setTimezone($property->timezone)->toDateString();
                    // shortcut: YYYY-MM-DD is the spec's provisional reading of the guide's date format; confirm with a real 2.13 response.
                    $read = CorrectionDecisionRead::query()->create([
                        'actor_id' => $actor->id,
                        'request_body' => ['startDate' => $local($submitted), 'endDate' => $local(now()), 'status' => '0'],
                    ]);
                    Audit::record($actor, 'document_correction_refresh_requested', $correction, 'Requested ZRA correction status', ['read_id' => $read->id]);

                    return $read;
                });
            } catch (RuntimeException $refused) {
                throw ValidationException::withMessages(['refresh' => __($refused->getMessage())]);
            }
            ReadCorrectionDecisions::dispatch($read->id);

            return $read;
        });
    }

    /** The worker side: rechecks the property, sends the recorded read once and records what it proves. */
    public function read(int $readId): void
    {
        $read = CorrectionDecisionRead::query()->whereNull('outcome')->find($readId);
        if ($read === null) {
            return;
        }
        $property = Tenancy::property() ?? throw new RuntimeException('Correction status reads require a property in context.');
        try {
            $this->assertRefreshable($property);
            $result = $this->http->send(['mode' => 'live', 'request_type' => 'ERRORS_MANAGEMENTS', 'path' => 'errors', 'body' => $read->request_body], VfmsCredential::for($property));
            $rows = $result->outcome === 'accepted' ? $this->rows($result->responseBody['body'] ?? null) : null;
            DB::transaction(function () use ($read, $result, $rows): void {
                $outcome = $result->outcome !== 'accepted' ? 'failed' : ($rows === null ? 'unresolved' : $this->observe($read, $rows));
                $read->forceFill(['response_body' => $result->responseBody, 'outcome' => $outcome, 'finished_at' => now()])->save();
            });
        } catch (Throwable $failure) {
            // A stuck read would block every later refresh; the failure keeps the prior decision.
            $read->forceFill(['response_body' => ['error' => $failure->getMessage()], 'outcome' => 'failed', 'finished_at' => now()])->save();
            if (! $failure instanceof RuntimeException) {
                report($failure);
            }
        }
    }

    private function assertRefreshable(Property $property): void
    {
        if (VfmsCredential::for($property)->mode !== VfmsMode::Live) {
            throw new RuntimeException('ZRA does not provide error correction in Sandbox.');
        }
        if ($property->demo || $property->archived_at !== null || ! JurisdictionRules::usesVfms($property)) {
            throw new RuntimeException('Issued-document corrections require an active real Zanzibar property.');
        }
    }

    /**
     * The provisional flat list (old_receipt_number, new_receipt_number, unitId, status), or null when any
     * row has another shape or an undocumented status: an unknown contract is never read heuristically.
     *
     * @return list<array{old: string, new: string, unit: int, decision: string}>|null
     */
    private function rows(mixed $body): ?array
    {
        if (! is_array($body) || ! array_is_list($body)) {
            return null;
        }
        $rows = [];
        foreach ($body as $row) {
            if (! is_array($row) || ! is_string($row['old_receipt_number'] ?? null) || ! is_string($row['new_receipt_number'] ?? null)
                || ! is_int($row['unitId'] ?? null) || ! isset(self::DECISIONS[$row['status'] ?? null])) {
                return null;
            }
            $rows[] = ['old' => $row['old_receipt_number'], 'new' => $row['new_receipt_number'], 'unit' => $row['unitId'], 'decision' => self::DECISIONS[$row['status']]];
        }

        return $rows;
    }

    /**
     * @param  list<array{old: string, new: string, unit: int, decision: string}>  $rows
     * @return 'observed'|'unresolved'
     */
    private function observe(CorrectionDecisionRead $read, array $rows): string
    {
        $outcome = 'observed';
        $actor = User::query()->findOrFail($read->actor_id);
        foreach (DocumentCorrection::query()->where('property_id', $read->property_id)->orderBy('id')->get() as $correction) {
            $own = array_filter($rows, fn (array $row) => $row['unit'] === $correction->unit_id
                && ($row['old'] === $correction->original_number || $row['new'] === $correction->replacement_number));
            if ($own === []) {
                continue;
            }
            $decisions = array_unique(array_column($own, 'decision'));
            $exact = array_filter($own, fn (array $row) => $row['old'] === $correction->original_number && $row['new'] === $correction->replacement_number);
            // One number without its pair, or two decisions for one pair, is not evidence about this correction.
            if (count($exact) !== count($own) || count($decisions) !== 1) {
                $outcome = 'unresolved';

                continue;
            }
            $decision = $decisions[array_key_first($decisions)];
            if (! $this->reconciled($actor, $correction, $read)) {
                continue;
            }
            $current = $correction->decision();
            if ($current === $decision) {
                continue;
            }
            if ($current !== 'awaiting' && (self::RANK[$current] === 2 || self::RANK[$decision] < self::RANK[$current])) {
                $outcome = 'unresolved';

                continue;
            }
            CorrectionDecision::query()->create(['document_correction_id' => $correction->id, 'correction_decision_read_id' => $read->id, 'status' => $decision]);
            Audit::record($actor, 'document_correction_decision_observed', $correction, 'Observed ZRA correction decision', ['read_id' => $read->id, 'status' => $decision]);
        }

        return $outcome;
    }

    /** An uncertain ERROR_SAVE becomes delivered only from this exact pair evidence; refused or unsent ones are left alone. */
    private function reconciled(User $actor, DocumentCorrection $correction, CorrectionDecisionRead $read): bool
    {
        $message = OutboxMessage::query()->where('property_id', $correction->property_id)->findOrFail($correction->outbox_message_id);
        if ($message->status === 'delivered') {
            return true;
        }
        if (! in_array($message->status, self::SENT, true)) {
            return false;
        }
        app(OutboxRecovery::class)->confirmAccepted($actor, $correction->property_id, $message->id, 'ZRA listed this exact correction pair', 'correction_decision_read:'.$read->id);

        return true;
    }
}
