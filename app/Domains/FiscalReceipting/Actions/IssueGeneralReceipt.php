<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\BookingGroupMembers;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues a general receipt (`WS`, spec #249): a ZRA receipt in a named customer's name for held
 * extras, one outbox message per currency on the subject's stream. The message claims its postings
 * in the same transaction, so no extra can reach two receipts.
 */
class IssueGeneralReceipt
{
    public function __construct(private readonly VfmsPayloads $payloads, private readonly Outbox $outbox) {}

    /**
     * Everything held on a paying-institution stay's booking group, in the institution's name.
     * Automatic issue (the group's last check-out) skips quietly when there is nothing to send.
     *
     * @return list<OutboxMessage>
     */
    public function forReservation(?User $actor, Reservation $reservation, bool $automatic = false): array
    {
        return Audit::validationRefusal($actor, $reservation, 'General receipt refused', fn () => DB::transaction(function () use ($actor, $reservation, $automatic) {
            $members = app(BookingGroupMembers::class)->reservations($reservation, lock: true);
            $stay = $members->find($reservation->id) ?? $reservation;
            $refused = OutboxMessage::query()->where('property_id', $stay->property_id)->where('integration', VfmsAdapter::INTEGRATION)
                ->where('subject_type', $stay->getMorphClass())->whereIn('subject_id', $members->modelKeys())
                ->where('event', 'generalReceipt')->where('status', 'rejected')->exists();
            $refusal = match (true) {
                $stay->getAttribute('paying_institution_type') === null => __('Only a stay paid by an institution gets an institution receipt.'),
                $refused => __('ZRA refused an earlier receipt for this group. Re-file it from the VFMS filings first.'),
                default => null,
            };
            $messages = $refusal === null ? $this->issue($actor, $stay, (string) $stay->getAttribute('paying_institution_name'), self::held($members)) : [];
            if ($messages === [] && ! $automatic) {
                throw ValidationException::withMessages(['general_receipt' => $refusal ?? __('Nothing is waiting for a ZRA receipt.')]);
            }

            return $messages;
        }));
    }

    /**
     * Sends $held, grouped by currency, in $customer's name. Files nothing for a demo, Mainland or not-yet-live property.
     *
     * @param  Builder<Posting>  $held
     * @return list<OutboxMessage>
     */
    public function issue(?User $actor, Reservation|CustomerAccount $subject, string $customer, Builder $held): array
    {
        return DB::transaction(function () use ($actor, $subject, $customer, $held) {
            $property = Property::query()->real()->find($subject->property_id);
            if ($property === null || ! $property->isLive() || ! JurisdictionRules::usesVfms($property)) {
                return [];
            }
            $customer = mb_substr(trim($customer), 0, 255);
            if ($customer === '') {
                throw ValidationException::withMessages(['general_receipt' => __('A ZRA receipt needs the customer\'s name.')]);
            }
            $credential = VfmsCredential::for($property);
            $messages = [];
            foreach ($held->lockForUpdate()->get()->groupBy(fn (Posting $posting) => $posting->currency->value) as $postings) {
                $id = (string) Str::ulid();
                $message = $this->outbox->record(VfmsAdapter::INTEGRATION, $subject, 'generalReceipt', [
                    'mode' => $credential->mode->value, 'unit_id' => $credential->unit_id,
                    'request_type' => 'ACCOMMODATION_ADD_SERVICE', 'path' => 'service/',
                    'reservation_id' => $subject instanceof Reservation ? $subject->id : null,
                    'customer_account_id' => $subject instanceof CustomerAccount ? $subject->id : null,
                    'receipt_id' => $id, 'posting_ids' => $postings->modelKeys(),
                    'body' => $this->payloads->generalReceipt($customer, $postings, 'WS-'.$id),
                ], "vfms-ws:{$id}");
                Posting::query()->whereKey($postings->modelKeys())->toBase()->update(['general_receipt_id' => $message->id]);
                Audit::record($actor, 'general_receipt_issued', $subject, 'General receipt sent to ZRA', [
                    'outbox_message_id' => $message->id, 'posting_ids' => $postings->modelKeys(), 'currency' => $postings->first()?->currency->value,
                ]);
                $messages[] = $message;
            }

            return $messages;
        });
    }

    /**
     * Extras on the group's paying-institution rooms with no receipt and no claim, not reversed or moved.
     *
     * @param  Collection<int, Reservation>  $members
     * @return Builder<Posting>
     */
    public static function held(Collection $members): Builder
    {
        $propertyId = $members->first()?->property_id;
        $institutionRooms = $members->filter(fn (Reservation $member) => $member->getAttribute('paying_institution_type') !== null)->modelKeys();

        return Posting::query()->where('postings.property_id', $propertyId)
            ->whereIn('folio_id', Folio::query()->select('id')->where('property_id', $propertyId)->whereIn('reservation_id', $institutionRooms))
            ->whereIn('type', ['charge', 'transfer_in'])->whereIn('category', PostExtraCharge::CATEGORIES)
            ->whereNull('vfms_receipt_number')->whereNull('general_receipt_id')
            ->whereNotExists(fn (QueryBuilder $query) => $query->from('postings as cancelling')->whereColumn('cancelling.related_posting_id', 'postings.id'))
            ->orderBy('posted_at')->orderBy('id');
    }

    /**
     * What the Issue ZRA receipt dialog shows before sending: the customer, the held items and a total per currency.
     * Null when this stay has nothing to send.
     *
     * @return array{customer: string, items: list<array<string, mixed>>, totals: list<array{currency: string, amount: int}>, url: string}|null
     */
    public static function preview(Reservation $reservation): ?array
    {
        $property = Property::query()->real()->find($reservation->property_id);
        if ($property === null || ! $property->isLive() || ! JurisdictionRules::usesVfms($property) || $reservation->getAttribute('paying_institution_type') === null) {
            return null;
        }
        $members = app(BookingGroupMembers::class)->reservations($reservation);
        $held = self::held($members)->get();
        if ($held->isEmpty()) {
            return null;
        }
        $stays = Folio::query()->where('property_id', $reservation->property_id)->whereIn('id', $held->pluck('folio_id'))->pluck('reservation_id', 'id');
        $rooms = Room::query()->whereIn('id', $members->pluck('room_id')->filter())->pluck('number', 'id');

        return [
            'customer' => (string) $reservation->getAttribute('paying_institution_name'),
            'items' => $held->map(fn (Posting $posting) => [
                ...$posting->only(['id', 'description', 'category', 'quantity', 'unit_price', 'discount', 'amount']), 'currency' => $posting->currency->value,
                'room' => $rooms->get((string) $members->find($stays->get($posting->folio_id))?->room_id), 'postedAt' => $posting->posted_at->toIso8601String(),
            ])->all(),
            'totals' => $held->groupBy(fn (Posting $posting) => $posting->currency->value)
                ->map(fn ($postings, string $currency) => ['currency' => $currency, 'amount' => (int) $postings->sum('amount')])->values()->all(),
            'url' => route('reservations.general-receipt.store', $reservation),
        ];
    }

    /** Issues anything still held once no room of the institution group is left to arrive or depart. */
    public function afterCheckOut(?User $actor, Reservation $reservation): void
    {
        if ($reservation->getAttribute('paying_institution_type') === null) {
            return;
        }
        $members = app(BookingGroupMembers::class)->reservations($reservation, lock: true);
        if ($members->every(fn (Reservation $member) => ! in_array($member->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true))) {
            $this->forReservation($actor, $reservation, automatic: true);
        }
    }
}
