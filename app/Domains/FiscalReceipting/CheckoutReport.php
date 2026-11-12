<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Actions\IssueGeneralReceipt;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\BookingGroupMembers;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\OutboxMessage;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The fiscal check-out report of a stay (glossary): VFMS's report number and the folio
 * as VFMS settled it. Final figures wait for every filing (FSC-07); already-issued documents
 * remain readable. Identity documents are projected only for authorized viewers.
 */
final class CheckoutReport
{
    /** @return list<string> */
    public static function numbers(Reservation $reservation): array
    {
        return OutboxMessage::query()->where('integration', VfmsAdapter::INTEGRATION)
            ->where('property_id', $reservation->property_id)
            ->where('subject_type', $reservation->getMorphClass())->where('subject_id', $reservation->id)
            ->where('status', 'delivered')->whereIn('event', ['checkout', 'guestDeparture'])->orderBy('sequence')->get()
            ->flatMap(function (OutboxMessage $message) {
                $references = $message->getAttribute('provider_references');
                $numbers = isset($references['reportNumbers']) ? json_decode($references['reportNumbers'], true) : [$references['reportNo'] ?? null];

                return is_array($numbers) && array_is_list($numbers) ? $numbers : [];
            })->filter(fn ($number) => is_string($number) && trim($number) !== '')->unique()->values()->all();
    }

    /** @return array<string, mixed> */
    public static function of(Reservation $reservation, bool $viewIdentity = false, bool $revealIdentity = false): array
    {
        // A group's documents read as one page from any of its rooms (spec #249).
        $members = app(BookingGroupMembers::class)->reservations($reservation);
        $messages = OutboxMessage::query()->where('integration', VfmsAdapter::INTEGRATION)->where('property_id', $reservation->property_id)
            ->where('subject_type', $reservation->getMorphClass())->whereIn('subject_id', $members->modelKeys())
            ->where('event', '!=', 'errorSave')
            ->with(['attempts' => fn ($query) => $query->where('outcome', 'accepted')->orderByDesc('attempt_number')])
            ->orderBy('created_at')->orderBy('sequence')->get();
        $documents = IssuedDocument::query()->where('property_id', $reservation->property_id)->whereIn('reservation_id', $members->modelKeys())
            ->orderBy('issued_at')->orderBy('id')->get();
        $rooms = Room::query()->whereIn('id', $members->pluck('room_id')->filter())->pluck('number', 'id');
        $roomOf = fn (?string $stayId) => $rooms->get((string) $members->find($stayId)?->room_id);
        $leads = Reservation::guestNames($members);
        $printUrl = fn (?IssuedDocument $document) => $document === null ? null : route('reservations.fiscal-report.print', [$reservation, 'document' => $document->id]);
        $document = fn (string $type, ?string $number, ?string $mode) => $documents->first(fn (IssuedDocument $row) => $row->type === $type && $row->number === $number && $row->mode === $mode);
        $guestReports = [];
        $business = null;
        foreach ($messages->where('status', 'delivered')->whereIn('event', ['checkout', 'guestDeparture']) as $message) {
            $attempt = $message->attempts->first();
            $body = $attempt?->response_body === null ? null : json_decode($attempt->response_body, true, flags: JSON_THROW_ON_ERROR)['body'] ?? null;
            if (! is_array($body) || app(CheckoutFigures::class)->of($body, $message->payload['body']) === null) {
                continue;
            }
            foreach ($body as $entry) {
                $number = $entry['reportNumber'];
                $guestUrl = self::reportUrl($message, $number);
                $business ??= array_filter(array_intersect_key($entry, array_flip(['businessName', 'street', 'tinNumber', 'vrnNumber', 'znumber'])), is_scalar(...));
                $mode = $message->getAttribute('provider_references')['mode'] ?? null;
                $currency = app(VfmsPayloads::class)->fromVfmsCurrency($entry['accommodation']['currency']);
                $guestReports[] = [
                    'number' => $number,
                    'mode' => $mode,
                    'name' => $entry['guest']['fullName'] ?? null,
                    'identity' => $viewIdentity ? array_intersect_key($entry['guest'], array_flip(['documentName', 'country', ...($revealIdentity ? ['documentNo'] : [])])) : null,
                    'departedEarly' => $message->event === 'guestDeparture',
                    'portalUrl' => $guestUrl,
                    'qrSvg' => $guestUrl === null ? null : self::qr($guestUrl),
                    'room' => $roomOf($message->subject_id),
                    'amount' => app(VfmsPayloads::class)->minor($entry['total']['totalAmount'], $currency), 'currency' => $currency->value,
                    'levy' => app(VfmsPayloads::class)->minor($entry['totalInfrastructure'], $currency),
                    'issuedAt' => $message->getAttribute('delivered_at')?->toIso8601String(), ...self::state($message),
                    'printUrl' => $printUrl($document('checkout_report', $number, $mode)),
                ];
            }
        }
        foreach ($documents->where('type', 'checkout_report')->whereNotIn('number', array_column($guestReports, 'number')) as $report) {
            $guestUrl = $report->getAttribute('viewer_url');
            $guestReports[] = [
                'number' => $report->number, 'mode' => $report->mode,
                'name' => $report->getAttribute('guest_name'), 'identity' => null,
                'departedEarly' => $messages->firstWhere('id', $report->getAttribute('outbox_message_id'))?->event === 'guestDeparture',
                'portalUrl' => $guestUrl, 'qrSvg' => $guestUrl === null ? null : self::qr($guestUrl),
                'room' => $roomOf($report->getAttribute('reservation_id')), 'amount' => $report->getAttribute('amount'), 'currency' => $report->getAttribute('currency'),
                'levy' => null, 'issuedAt' => $report->getAttribute('issued_at')?->toIso8601String(), 'status' => 'issued', 'reason' => null, 'retryUrl' => null, 'refileUrl' => null,
                'printUrl' => $printUrl($report),
            ];
        }
        // Check-outs ZRA has not accepted yet: no number, but staff see they are pending or need attention.
        foreach ($messages->whereIn('event', ['checkout', 'guestDeparture'])->where('status', '!=', 'delivered') as $message) {
            $guestReports[] = [
                'number' => null, 'mode' => $message->payload['mode'] ?? null, 'name' => $leads->get($message->subject_id), 'identity' => null,
                'departedEarly' => $message->event === 'guestDeparture', 'portalUrl' => null, 'qrSvg' => null,
                'room' => $roomOf($message->subject_id), 'amount' => null, 'currency' => null, 'levy' => null, 'issuedAt' => null,
                ...self::state($message, $members->find($message->subject_id)), 'printUrl' => null,
            ];
        }
        // Postings a general receipt claimed carry its number too; they are listed as general receipts.
        $serviceReceipts = Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('property_id', $reservation->property_id)->whereIn('reservation_id', $members->modelKeys()))
            ->whereNotNull('vfms_receipt_number')->whereNull('general_receipt_id')->orderBy('posted_at')->orderBy('id')->get()
            ->map(function (Posting $posting) use ($messages) {
                $service = $messages->first(fn (OutboxMessage $message) => $message->event === 'services'
                    && $message->status === 'delivered'
                    && ($message->payload['posting_id'] ?? null) === $posting->id
                    && ($message->getAttribute('provider_references')['receiptNumber'] ?? null) === $posting->vfms_receipt_number);
                $mode = $service?->getAttribute('provider_references')['mode'] ?? null;
                $receiptUrl = $service?->getAttribute('provider_references')['receiptUrl'] ?? null;

                return [
                    'number' => $posting->vfms_receipt_number, 'description' => $posting->description, 'amount' => $posting->amount,
                    'currency' => $posting->currency->value,
                    'mode' => $mode, 'portalUrl' => $receiptUrl, 'qrSvg' => $receiptUrl === null ? null : self::qr($receiptUrl),
                    'stay' => $service?->subject_id, 'issuedAt' => $service?->getAttribute('delivered_at')?->toIso8601String(),
                ];
            })->all();
        $issuedServices = $documents->where('type', 'services_receipt');
        $guest = fn (?string $stayId) => ['guest' => $leads->get((string) $stayId), 'room' => $roomOf($stayId)];
        $serviceReceipts = [
            ...$issuedServices->map(function (IssuedDocument $document) use ($serviceReceipts, $guest, $printUrl): array {
                $url = $document->getAttribute('viewer_url');

                return [
                    'number' => $document->number,
                    'description' => collect($serviceReceipts)->firstWhere('number', $document->number)['description'] ?? null,
                    'amount' => $document->getAttribute('amount'), 'currency' => $document->getAttribute('currency'),
                    'mode' => $document->mode, 'portalUrl' => $url, 'qrSvg' => $url === null ? null : self::qr($url),
                    ...$guest($document->getAttribute('reservation_id')), 'issuedAt' => $document->getAttribute('issued_at')?->toIso8601String(),
                    'status' => 'issued', 'reason' => null, 'retryUrl' => null, 'refileUrl' => null, 'printUrl' => $printUrl($document),
                ];
            })->values()->all(),
            ...array_values(array_map(fn (array $receipt) => [...array_diff_key($receipt, ['stay' => true]), ...$guest($receipt['stay']),
                'status' => 'issued', 'reason' => null, 'retryUrl' => null, 'refileUrl' => null, 'printUrl' => null,
            ], array_filter($serviceReceipts, fn (array $receipt) => ! $issuedServices->contains('number', $receipt['number'])))),
            ...$messages->where('event', 'services')->where('status', '!=', 'delivered')->map(fn (OutboxMessage $message) => [
                'number' => null, 'description' => null, 'amount' => self::filed($message)['amount'], 'currency' => self::filed($message)['currency'],
                'mode' => $message->payload['mode'] ?? null, 'portalUrl' => null, 'qrSvg' => null, ...$guest($message->subject_id),
                'issuedAt' => null, ...self::state($message, $members->find($message->subject_id)), 'printUrl' => null,
            ])->values()->all(),
        ];
        $generalReceipts = $messages->where('event', 'generalReceipt')->map(function (OutboxMessage $message) use ($documents, $members, $printUrl) {
            $issued = $documents->first(fn (IssuedDocument $document) => $document->type === 'general_receipt' && $document->getAttribute('outbox_message_id') === $message->id);
            $url = $issued?->getAttribute('viewer_url');

            return [
                'number' => $issued->number ?? $message->getAttribute('provider_references')['receiptNumber'] ?? null, 'customer' => $message->payload['body']['documentNumber'], 'items' => count($message->payload['body']['service']),
                ...($issued === null ? self::filed($message) : ['amount' => $issued->getAttribute('amount'), 'currency' => $issued->getAttribute('currency')]),
                'mode' => $issued->mode ?? $message->payload['mode'] ?? null, 'issuedAt' => $issued?->getAttribute('issued_at')?->toIso8601String(),
                'portalUrl' => $url, 'qrSvg' => $url === null ? null : self::qr($url),
                ...self::state($message, $members->find($message->subject_id)), 'printUrl' => $printUrl($issued),
            ];
        })->values()->all();
        $group = [
            'rooms' => $members->count(),
            'payingInstitution' => $reservation->paying_institution_type === null ? null : [
                'name' => $reservation->paying_institution_name, 'zrbNumber' => $reservation->paying_institution_zrb_number,
            ],
            'generalReceipts' => $generalReceipts,
            'held' => IssueGeneralReceipt::preview($reservation)['items'] ?? [],
        ];
        $group['summary'] = self::summary($members, $group['held']);
        $group['sandbox'] = VfmsCredential::for(Property::query()->findOrFail($reservation->property_id))->mode === VfmsMode::Sandbox
            || collect([...$guestReports, ...$serviceReceipts, ...$generalReceipts])->contains('mode', VfmsMode::Sandbox->value);
        $own = $messages->where('subject_id', $reservation->id);
        $checkout = $own->firstWhere('event', 'checkout');
        // A general receipt carries its own status; only the stay's own filings hold back its report (FSC-07).
        if ($checkout === null || $own->contains(fn (OutboxMessage $message) => $message->event !== 'generalReceipt' && $message->status !== 'delivered')) {
            return [
                'status' => 'waiting', 'report' => null, 'portalUrl' => null, 'qrSvg' => null,
                'guestReports' => $guestReports, 'business' => $business, 'serviceReceipts' => $serviceReceipts,
                'needsAttention' => $checkout !== null && $checkout->status === 'unknown', ...$group,
            ];
        }
        $references = $checkout->getAttribute('provider_references');
        $payloads = app(VfmsPayloads::class);
        $currency = $payloads->fromVfmsCurrency($checkout->payload['body']['currency']);
        $summary = collect(FolioSummary::totals($reservation))->firstWhere('currency', $currency->value);
        $live = ($references['mode'] ?? null) === VfmsMode::Live->value;
        $url = $live ? self::reportUrl($checkout, (string) ($references['reportNo'] ?? '')) : null;

        return [
            'status' => $live ? 'live' : 'sandbox',
            'identityRevealed' => $viewIdentity && $revealIdentity,
            'report' => [
                'number' => $references['reportNo'] ?? null, 'currency' => $currency->value,
                'room' => isset($references['roomAmount']) ? $payloads->minor($references['roomAmount'], $currency) : ($summary['room'] ?? 0),
                'extras' => isset($references['extrasAmount']) ? $payloads->minor($references['extrasAmount'], $currency) : ($summary['extras'] ?? 0),
                'vat' => $summary['vat'] ?? 0,
                'levy' => $summary['levy'] ?? 0, 'total' => $summary['charges'] ?? 0,
            ],
            'portalUrl' => $url,
            'qrSvg' => $url === null ? null : self::qr($url),
            'guestReports' => $guestReports,
            'business' => $business,
            'needsAttention' => false,
            'serviceReceipts' => $serviceReceipts,
            ...$group,
        ];
    }

    /**
     * Sending to ZRA, Issued, or Needs attention with ZRA's reason and the existing Retry or Re-file.
     *
     * @return array{status: 'issued'|'sending'|'attention', reason: string|null, retryUrl: string|null, refileUrl: string|null}
     */
    public static function state(OutboxMessage $message, Reservation|CustomerAccount|null $stay = null): array
    {
        $recover = $stay !== null && Gate::allows('recoverVfms', $stay);
        $routes = $stay instanceof CustomerAccount ? 'customer-accounts' : 'reservations';

        return [
            'status' => match ($message->status) {
                'delivered' => 'issued',
                'rejected', 'unknown', 'exhausted', 'exhausted_unknown' => 'attention',
                default => 'sending',
            },
            'reason' => match ($message->status) {
                'rejected' => VfmsRefusal::summary($message),
                'unknown', 'exhausted_unknown' => __('ZRA did not confirm this filing. Check it in the VFMS portal before doing anything else.'),
                'exhausted' => __('ZRA could not be reached. Retry when it is back.'),
                default => null,
            },
            'retryUrl' => $recover && in_array($message->status, ['retryable', 'exhausted'], true) ? route($routes.'.vfms.retry', [$stay, $message]) : null,
            'refileUrl' => $recover && $message->status === 'rejected' ? route($routes.'.vfms.refile', [$stay, $message]) : null,
        ];
    }

    /**
     * The amount Karafuu sent, for a receipt ZRA has not issued yet.
     *
     * @return array{amount: int, currency: string}
     */
    public static function filed(OutboxMessage $message): array
    {
        $payloads = app(VfmsPayloads::class);
        $body = $message->payload['body'];
        $currency = $payloads->fromVfmsCurrency($body['currency']);

        return ['amount' => $payloads->minor(array_sum(array_map(fn (array $item) => $item['price'] * $item['quantity'] - $item['discount'], $body['service'])), $currency), 'currency' => $currency->value];
    }

    /**
     * The group's folio totals per currency, with what is still waiting for a ZRA receipt; currencies never added together.
     *
     * @param  Collection<int, Reservation>  $members
     * @param  list<array<string, mixed>>  $held
     * @return list<array<string, mixed>>
     */
    private static function summary(Collection $members, array $held): array
    {
        return Folio::query()->whereIn('reservation_id', $members->modelKeys())->where('property_id', $members->first()?->property_id)->with('postings')->get()
            ->groupBy(fn (Folio $folio) => $folio->currency->value)->sortKeys()
            ->map(fn ($folios, string $currency) => [
                'currency' => $currency,
                ...array_intersect_key(FolioSummary::of($folios->flatMap(fn (Folio $folio) => $folio->postings)), array_flip(['room', 'extras', 'vat', 'levy', 'charges'])),
                'held' => (int) collect($held)->where('currency', $currency)->sum('amount'),
            ])->values()->all();
    }

    /** The original viewer destination is unavailable when it was not stored. */
    private static function reportUrl(OutboxMessage $message, string $number): ?string
    {
        $references = $message->getAttribute('provider_references');
        $stored = is_string($references['reportUrls'] ?? null) ? json_decode($references['reportUrls'], true) : null;

        return is_array($stored) && is_string($stored[$number] ?? null)
            ? $stored[$number]
            : null;
    }

    /** ZRA's viewer link: checkout-report/guest for a report (ZRA's portal shows every report in USD, converted), receipt-form for a service receipt. */
    public static function viewerUrl(bool $live, string $path, string $number): ?string
    {
        $base = $live ? 'https://portalvfms.zanrevenue.org' : config('services.vfms.test_viewer_url');
        if (blank($base) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        return rtrim($base, '/').'/'.$path.'/'.rawurlencode($number);
    }

    public static function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 4), new SvgImageBackEnd)))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
