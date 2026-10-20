<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Reservations\Actions\ListReservations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ListReservationsRequest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationListController extends Controller
{
    public function __invoke(ListReservationsRequest $request, ListReservations $lists): Response
    {
        $date = $request->validated('date', BusinessDate::current());
        $list = $request->validated('list', 'arrivals');
        $reservations = $lists->handle($list, $date, (string) $request->validated('search', ''));

        return Inertia::render('reservations/index', [
            'filters' => ['date' => $date, 'list' => $list, 'search' => $request->validated('search', '')],
            'reservations' => $reservations,
            'blockCheckoutIfUnpaid' => Tenancy::property()?->settings()['payment_settings']['block_checkout_if_unpaid'] ?? true,
            'canImport' => Gate::allows('create', ImportJob::class),
            'importLabel' => __('Import future reservations'),
        ]);
    }
}
