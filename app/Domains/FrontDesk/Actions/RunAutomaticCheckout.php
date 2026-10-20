<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Mail\AutomaticCheckoutSummary;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class RunAutomaticCheckout
{
    public function handle(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            $locked = Property::query()->active()->whereKey($property->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            $settings = $locked->settings()['front_desk_settings'];
            $local = now($locked->timezone);
            $date = $local->toDateString();
            if (! $settings['automatic_checkout_enabled']
                || $local->format('H:i') < $settings['automatic_checkout_time']
                || $locked->automatic_checkout_date?->toDateString() === $date) {
                return;
            }

            $checkedOut = [];
            $skipped = [];
            $businessDate = BusinessDate::of($locked);
            $stays = Reservation::query()->where('property_id', $locked->id)
                ->where('status', ReservationStatus::CheckedIn)
                ->whereDate('departure', '<=', $businessDate)->orderBy('id')->get();
            foreach ($stays as $stay) {
                if ($stay->departure->toDateString() < $businessDate && ! $settings['automatic_checkout_include_overstays']) {
                    $skipped[$stay->reservation_number] = __('Overstays are excluded from automatic check-out.');

                    continue;
                }
                try {
                    app(CheckOut::class)->handle(null, $stay);
                    $checkedOut[] = $stay->reservation_number;
                } catch (ValidationException $refused) {
                    $skipped[$stay->reservation_number] = implode(' ', $refused->validator->errors()->all());
                }
            }

            $locked->forceFill(['automatic_checkout_date' => $date])->save();
            Audit::record(null, 'automatic_checkout', $locked, 'Automatic check-out completed', [
                'date' => $date, 'checked_out' => $checkedOut, 'skipped' => $skipped,
            ]);
            Mail::to($settings['automatic_checkout_summary_email'])->queue(
                (new AutomaticCheckoutSummary($locked->name, $date, $checkedOut, $skipped))->afterCommit(),
            );
        }, attempts: 3);
    }
}
