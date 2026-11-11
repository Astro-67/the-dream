<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\FiscalReceipting\Actions\CorrectPayingInstitution;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CorrectPayingInstitutionRequest;
use Illuminate\Http\RedirectResponse;

class PayingInstitutionController extends Controller
{
    public function __invoke(CorrectPayingInstitutionRequest $request, Reservation $reservation, CorrectPayingInstitution $correct): RedirectResponse
    {
        $unchanged = $correct->handle($request->user(), $reservation, $request->validated());

        return to_route('reservations.show', $reservation)->with('toast', $unchanged === []
            ? ['type' => 'success', 'message' => __('Paying institution updated.')]
            : ['type' => 'warning', 'message' => __('Paying institution updated. Unchanged because ZRA has their check-in or it is pending: :numbers.', ['numbers' => implode(', ', $unchanged)])]);
    }
}
