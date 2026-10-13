<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Opens the actor's cashier shift in the property in context, with a float per currency. */
class OpenCashierShift
{
    /** @param array<string, int> $floats minor units keyed by currency code */
    public function handle(User $actor, array $floats): CashierShift
    {
        $property = Tenancy::property() ?? throw new \LogicException('A cashier shift needs a property in context.');

        return Audit::validationRefusal($actor, $property, 'Cashier shift open refused', fn () => DB::transaction(function () use ($actor, $floats, $property) {
            $allowed = array_map(fn ($currency) => $currency->value, JurisdictionRules::allowedCurrencies($property));
            foreach ($floats as $currency => $amount) {
                if (! in_array($currency, $allowed, true) || $amount < 0) {
                    throw ValidationException::withMessages(['floats' => __('Enter a float of zero or more in each currency this property uses.')]);
                }
            }
            $refused = ValidationException::withMessages(['shift' => __('You already have an open shift at this property.')]);
            if (CashierShift::query()->where('open_user_id', $actor->id)->exists()) {
                throw $refused;
            }
            try {
                $shift = CashierShift::query()->create([
                    'user_id' => $actor->id, 'open_user_id' => $actor->id, 'status' => 'open',
                    'floats' => array_map('intval', $floats), 'opened_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $refused;
            }
            Audit::record($actor, 'created', $shift, 'Cashier shift opened', ['floats' => $shift->floats]);

            return $shift;
        }));
    }
}
