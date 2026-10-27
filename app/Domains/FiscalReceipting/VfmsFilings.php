<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Money\Currency;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * VFMS calls as staff see them: one stay's calls for the reservation page, and a
 * property's calls by business date for the ZRA filings report. Never exposes guest documents.
 */
final class VfmsFilings
{
    private const CALLS = [
        'accommodation' => 'Check-in', 'changeRoom' => 'Room move', 'changeRoomPrice' => 'Room price changed',
        'addGuest' => 'Guest added', 'changeProfile' => 'Profile corrected', 'services' => 'Extras',
        'guestDeparture' => 'Guest left', 'checkout' => 'Check-out',
    ];

    /** Filing statuses in display order, with their labels. */
    private const STATUSES = ['filed' => 'Filed', 'refused' => 'Refused', 'unknown' => 'Unknown', 'waiting' => 'Waiting'];

    /** @return list<array{id: string, call: string, status: string, attempts: int, number: string|null, message: string|null, refileUrl: string|null, retryUrl: string|null}> */
    public static function of(Reservation $reservation, bool $canRecover): array
    {
        return OutboxMessage::query()->where('property_id', $reservation->property_id)
            ->where('integration', VfmsAdapter::INTEGRATION)->where('subject_type', $reservation->getMorphClass())
            ->where('subject_id', $reservation->id)->orderBy('sequence')->get()
            ->map(function (OutboxMessage $message) use ($reservation, $canRecover) {
                $references = $message->getAttribute('provider_references') ?? [];
                $number = $references['reportNo'] ?? $references['receiptNumber'] ?? $references['responseNumber'] ?? null;

                return [
                    'id' => $message->id,
                    'call' => self::call($message),
                    'status' => self::status($message->status),
                    'attempts' => (int) $message->attempt_count,
                    'number' => $number === null ? null : (string) $number,
                    'message' => $message->status === 'rejected' ? VfmsRefusal::summary($message) : null,
                    // Unknown outcomes never get an action: VFMS cannot prove whether ZRA kept them (unknownRetrySafe is false).
                    'refileUrl' => $canRecover && $message->status === 'rejected' ? route('reservations.vfms.refile', [$reservation, $message]) : null,
                    'retryUrl' => $canRecover && in_array($message->status, ['retryable', 'exhausted'], true) ? route('reservations.vfms.retry', [$reservation, $message]) : null,
                ];
            })->all();
    }

    /**
     * @return array{
     *     counts: list<array{status: string, label: string, count: int}>,
     *     calls: LengthAwarePaginator<int, array{id: string, date: string, reservationNumber: string, call: string, amount: int|null, currency: string|null, status: string, statusLabel: string, zraNumber: string|null}>,
     * }
     */
    public function forProperty(Property $property, string $from, string $to): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        $byOutboxStatus = $this->query($property, $from, $to)->select([])->reorder()->groupBy('outbox_messages.status')
            ->selectRaw('outbox_messages.status, COUNT(*) as calls')->toBase()->pluck('calls', 'status');
        foreach ($byOutboxStatus as $status => $calls) {
            $counts[self::status((string) $status)] += (int) $calls;
        }

        return [
            'counts' => array_map(fn (string $status) => ['status' => $status, 'label' => __(self::STATUSES[$status]), 'count' => $counts[$status]], array_keys(self::STATUSES)),
            'calls' => $this->query($property, $from, $to)->paginate(50)->withQueryString()->through($this->row(...)),
        ];
    }

    /** @return list<array{id: string, date: string, reservationNumber: string, call: string, amount: int|null, currency: string|null, status: string, statusLabel: string, zraNumber: string|null}> */
    public function rows(Property $property, string $from, string $to): array
    {
        return $this->query($property, $from, $to)->get()->map($this->row(...))->values()->all();
    }

    /** Minor units as a plain decimal for CSV: TZS has no decimals, USD and EUR two. */
    public static function decimal(int $minor, string $currency): string
    {
        $decimals = $currency === Currency::TZS->value ? 0 : 2;

        return number_format($minor / 10 ** $decimals, $decimals, '.', '');
    }

    private static function status(string $outboxStatus): string
    {
        return match ($outboxStatus) {
            'delivered' => 'filed',
            'rejected' => 'refused',
            'unknown', 'exhausted_unknown' => 'unknown',
            default => 'waiting',
        };
    }

    private static function call(OutboxMessage $message): string
    {
        return __(self::CALLS[$message->event] ?? $message->event);
    }

    /** @return Builder<OutboxMessage> */
    private function query(Property $property, string $from, string $to): Builder
    {
        $property = Property::query()->real()->findOrFail($property->id);

        // The idempotency key starts "vfms:{lifecycle event ULID}" (VfmsTranslator), kept across re-filing.
        return OutboxMessage::query()
            ->join('reservation_lifecycle_events as events', DB::raw('SUBSTR(outbox_messages.idempotency_key, 6, 26)'), '=', 'events.id')
            ->join('reservations', 'reservations.id', '=', 'outbox_messages.subject_id')
            ->where('outbox_messages.property_id', $property->id)->where('events.property_id', $property->id)->where('reservations.property_id', $property->id)
            ->where('outbox_messages.integration', VfmsAdapter::INTEGRATION)
            // Superseded messages were replaced by a later one and were never filed.
            ->where('outbox_messages.status', '!=', 'superseded')
            ->whereDate('events.business_date', '>=', $from)->whereDate('events.business_date', '<=', $to)
            ->select('outbox_messages.*', 'events.business_date as filed_on', 'reservations.reservation_number')
            ->orderBy('events.business_date')->orderBy('reservations.reservation_number')->orderBy('outbox_messages.sequence');
    }

    /** @return array{id: string, date: string, reservationNumber: string, call: string, amount: int|null, currency: string|null, status: string, statusLabel: string, zraNumber: string|null} */
    private function row(OutboxMessage $message): array
    {
        $status = self::status($message->status);
        $references = $message->getAttribute('provider_references') ?? [];
        $body = $message->payload['body'] ?? [];
        $payloads = app(VfmsPayloads::class);
        $currency = isset($body['currency']) ? $payloads->fromVfmsCurrency($body['currency']) : null;
        $major = match ($message->event) {
            'accommodation', 'changeRoom' => $body['accommodationPrice'] ?? null,
            'services' => array_sum(array_map(fn (array $item) => $item['price'] * $item['quantity'] - $item['discount'], $body['service'] ?? [])),
            'checkout', 'guestDeparture' => $references['totalAmount'] ?? null,
            // shortcut: a room price call carries no currency, so no amount; add the folio currency if staff need it.
            default => null,
        };
        // A check-out has one report per guest; list them all.
        $numbers = isset($references['reportNumbers']) ? json_decode($references['reportNumbers'], true) : null;
        $number = is_array($numbers) ? implode(', ', $numbers) : ($references['reportNo'] ?? $references['receiptNumber'] ?? $references['responseNumber'] ?? null);

        return [
            'id' => $message->id,
            'date' => substr((string) $message->getAttribute('filed_on'), 0, 10),
            'reservationNumber' => (string) $message->getAttribute('reservation_number'),
            'call' => self::call($message),
            'amount' => $major === null || $currency === null ? null : $payloads->minor((float) $major, $currency),
            'currency' => $currency?->value,
            'status' => $status,
            'statusLabel' => __(self::STATUSES[$status]),
            'zraNumber' => $number === null ? null : (string) $number,
        ];
    }
}
