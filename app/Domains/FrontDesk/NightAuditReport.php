<?php

namespace App\Domains\FrontDesk;

use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\FrontDesk\Models\ExceptionListItem;
use App\Domains\Reservations\Models\NoShowProposal;
use Illuminate\Support\Facades\Gate;

/**
 * Reads for the night audit pages: close history, the current exception list
 * and one close's summary. Runs in the property's tenant context.
 */
final class NightAuditReport
{
    /** @return array<string, mixed> */
    public function index(): array
    {
        $businessDate = BusinessDate::current();

        return [
            'closes' => BusinessDateClose::query()->with('actor:id,name')->orderByDesc('business_date')
                ->paginate(50)->withQueryString()->through(fn (BusinessDateClose $close) => self::row($close)),
            'businessDate' => $businessDate,
            'exceptions' => ExceptionListItem::query()->whereDate('business_date', $businessDate)->orderBy('kind')->orderBy('reference')
                ->get(['id', 'kind', 'reference']),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(BusinessDateClose $close): array
    {
        $date = $close->business_date->toDateString();
        $charges = Posting::query()->where('property_id', $close->property_id)->whereDate('business_date', $date)
            ->where('category', 'room')->where('type', 'charge')->with('reservation:id,reservation_number')
            ->orderBy('id')->paginate(50)->withQueryString()->through(fn (Posting $posting) => [
                'id' => $posting->id, 'reservation_number' => $posting->reservation?->reservation_number,
                'price_line_id' => $posting->price_line_id, 'unit_price' => $posting->unit_price,
                'net' => $posting->net, 'amount' => $posting->amount, 'currency' => $posting->currency->value,
                'tax_lines' => $posting->tax_lines,
            ]);
        $noShows = NoShowProposal::query()->where('property_id', $close->property_id)->whereDate('business_date', $date)
            ->with('reservation:id,reservation_number')->orderBy('id')->get();

        return [
            'close' => self::row($close), 'charges' => $charges,
            'noShowsProposed' => $noShows->count(),
            'exceptionsCarried' => ExceptionListItem::query()->where('business_date_close_id', $close->id)->count(),
            'noShows' => $noShows->map(fn (NoShowProposal $proposal) => [
                'id' => $proposal->id, 'reservation_number' => $proposal->reservation->reservation_number,
                'status' => $proposal->status, 'can_resolve' => $proposal->status === 'proposed' && Gate::allows('resolve', $proposal),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(BusinessDateClose $close): array
    {
        return [
            'id' => $close->id, 'business_date' => $close->business_date->toDateString(),
            'actor' => $close->actor->name ?? __('Automation'),
            'completed_at' => $close->completed_at?->toIso8601String(),
        ];
    }
}
