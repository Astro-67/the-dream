<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\CloseCashierShift;
use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CloseCashierShiftRequest;
use App\Http\Requests\Billing\OpenCashierShiftRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CashierShiftController extends Controller
{
    /** Cashiers see their own open shift; finance also sees every shift of the property. */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $canOpen = Gate::allows('create', CashierShift::class);
        $canList = Gate::allows('viewAny', CashierShift::class);
        abort_unless($canOpen || $canList, 403);
        $own = CashierShift::query()->where('open_user_id', $user->id)->first();
        $shifts = $canList ? CashierShift::query()->with('user:id,name')->latest('opened_at')->latest('id')->paginate(50)->withQueryString() : null;
        $shifts?->through(fn (CashierShift $shift) => $this->row($shift));
        $property = Tenancy::property();

        return Inertia::render('cashier-shifts/index', [
            'canOpenShift' => $canOpen && ($property?->settings()['payment_settings']['use_cashier_shifts'] ?? false),
            'canCloseShift' => $own !== null && Gate::allows('close', $own),
            'usesCashierShifts' => $property?->settings()['payment_settings']['use_cashier_shifts'] ?? false,
            'openShift' => $own === null ? null : $this->row($own),
            'shifts' => $shifts,
            'currencies' => $property === null ? [] : array_map(fn ($currency) => $currency->value, JurisdictionRules::allowedCurrencies($property)),
        ]);
    }

    public function store(OpenCashierShiftRequest $request, OpenCashierShift $open): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $open->handle($user, $request->floats());

        return to_route('cashier-shifts.index')->with('toast', ['type' => 'success', 'message' => __('Shift opened.')]);
    }

    public function close(CloseCashierShiftRequest $request, CashierShift $cashierShift, CloseCashierShift $close): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string|null $reason */
        $reason = $request->validated('variance_reason');
        $close->handle($user, $cashierShift, $request->counted(), $reason);

        return to_route('cashier-shifts.index')->with('toast', ['type' => 'success', 'message' => __('Shift closed.')]);
    }

    /** @return array<string, mixed> */
    private function row(CashierShift $shift): array
    {
        // ponytail: an open shift's expected cash is summed per row; fine while few shifts are open at once.
        $expected = $shift->expectedCash();
        $variance = $shift->variance();

        return [
            'id' => $shift->id, 'cashier' => $shift->user?->name, 'status' => $shift->status,
            'opened_at' => $shift->opened_at->toIso8601String(), 'closed_at' => $shift->closed_at?->toIso8601String(),
            'variance_reason' => $shift->variance_reason,
            'currencies' => array_map(fn (string $currency) => [
                'currency' => $currency, 'float' => $shift->floats[$currency] ?? 0, 'expected' => $expected[$currency],
                'counted' => $shift->counted[$currency] ?? null, 'variance' => $variance[$currency] ?? null,
            ], array_keys($expected)),
        ];
    }
}
