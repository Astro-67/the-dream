<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * A property's VFMS calls by the business date of the stay event each one files,
 * with ZRA's number and the amount filed. Never exposes guest documents.
 */
final class VfmsFilings
{
    /** Outbox event => call name staff see. */
    public const CALLS = [
        'accommodation' => 'Check-in',
        'changeRoom' => 'Room move',
        'changeRoomPrice' => 'Room price',
        'addGuest' => 'Guest added',
        'changeProfile' => 'Profile corrected',
        'services' => 'Extras',
        'guestDeparture' => 'Guest left',
        'checkout' => 'Check-out',
    ];

    /** Filing status => outbox statuses it covers. Superseded messages were replaced and are left out. */
    public const STATUSES = [
        'filed' => ['delivered'],
        'refused' => ['rejected', 'exhausted'],
        'unknown' => ['unknown', 'exhausted_unknown'],
        'waiting' => ['pending', 'sending', 'retryable', 'blocked'],
    ];

    /**
     * @return array{
     *     counts: list<array{status: string, label: string, count: int}>,
     *     calls: LengthAwarePaginator<int, array{id: string, date: string, reservationNumber: string, call: string, amount: int|null, currency: string|null, status: string, statusLabel: string, zraNumber: string|null}>,
     * }
     */
    public function forProperty(Property $property, string $from, string $to): array
    {
        $counts = $this->query($property, $from, $to)->reorder()->groupBy('outbox_messages.status')
            ->selectRaw('outbox_messages.status, COUNT(*) as calls')->toBase()->pluck('calls', 'status');

        return [
            'counts' => array_map(fn (string $status) => [
                'status' => $status,
                'label' => self::label($status),
                'count' => (int) $counts->only(self::STATUSES[$status])->sum(),
            ], array_keys(self::STATUSES)),
            'calls' => $this->query($property, $from, $to)->paginate(50)->withQueryString()->through($this->row(...)),
        ];
    }

    /** @return LazyCollection<int, array{id: string, date: string, reservationNumber: string, call: string, amount: int|null, currency: string|null, status: string, statusLabel: string, zraNumber: string|null}> */
    public function rows(Property $property, string $from, string $to): LazyCollection
    {
        return $this->query($property, $from, $to)->lazy()->map($this->row(...));
    }

    public static function label(string $status): string
    {
        return __(ucfirst($status));
    }

    /** @return Builder<OutboxMessage> */
    private function query(Property $property, string $from, string $to): Builder
    {
        // The idempotency key starts "vfms:{lifecycle event ULID}" (VfmsTranslator), kept across re-filing.
        return OutboxMessage::query()
            ->join('reservation_lifecycle_events as events', DB::raw('SUBSTR(outbox_messages.idempotency_key, 6, 26)'), '=', 'events.id')
            ->join('reservations', 'reservations.id', '=', 'outbox_messages.subject_id')
            ->where('outbox_messages.property_id', $property->id)->where('events.property_id', $property->id)->where('reservations.property_id', $property->id)
            ->where('outbox_messages.integration', VfmsAdapter::INTEGRATION)
            ->where('outbox_messages.status', '!=', 'superseded')
            ->whereDate('events.business_date', '>=', $from)->whereDate('events.business_date', '<=', $to)
            ->select('outbox_messages.*', 'events.business_date as filed_on', 'reservations.reservation_number')
            ->orderBy('events.business_date')->orderBy('reservations.reservation_number')->orderBy('outbox_messages.sequence');
    }

    /** @return array{id: string, date: string, reservationNumber: string, call: string, amount: int|null, currency: string|null, status: string, statusLabel: string, zraNumber: string|null} */
    private function row(OutboxMessage $message): array
    {
        $status = array_find_key(self::STATUSES, fn (array $statuses) => in_array($message->status, $statuses, true)) ?? 'waiting';
        $references = $message->getAttribute('provider_references') ?? [];
        $body = $message->payload['body'] ?? [];
        $currency = isset($body['currency']) ? app(VfmsPayloads::class)->fromVfmsCurrency($body['currency']) : null;
        $major = match ($message->event) {
            'accommodation', 'changeRoom' => $body['accommodationPrice'] ?? null,
            'services' => array_sum(array_map(fn (array $item) => $item['price'] * $item['quantity'] - $item['discount'], $body['service'] ?? [])),
            'checkout', 'guestDeparture' => $references['totalAmount'] ?? null,
            // shortcut: a room price call carries no currency, so no amount; add the folio currency if staff need it.
            default => null,
        };
        $numbers = isset($references['reportNumbers']) ? json_decode($references['reportNumbers'], true) : null;

        return [
            'id' => $message->id,
            'date' => substr((string) $message->getAttribute('filed_on'), 0, 10),
            'reservationNumber' => (string) $message->getAttribute('reservation_number'),
            'call' => __(self::CALLS[$message->event] ?? $message->event),
            'amount' => $major === null || $currency === null ? null : app(VfmsPayloads::class)->minor((float) $major, $currency),
            'currency' => $currency?->value,
            'status' => $status,
            'statusLabel' => self::label($status),
            'zraNumber' => is_array($numbers) ? implode(', ', $numbers) : ($references['receiptNumber'] ?? $references['reportNo'] ?? $references['responseNumber'] ?? null),
        ];
    }
}
