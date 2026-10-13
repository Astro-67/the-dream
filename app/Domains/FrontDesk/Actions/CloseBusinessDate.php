<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Housekeeping\Actions\MarkStayoverService;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseBusinessDate
{
    public function handle(?User $actor, Property $property, string $date): BusinessDateClose
    {
        return Audit::validationRefusal($actor, $property, 'Night audit refused', fn () => DB::transaction(function () use ($actor, $property, $date) {
            if (Tenancy::property()?->id !== $property->id) {
                throw ValidationException::withMessages(['property' => __('Night audit requires the property in context.')]);
            }
            $property = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            $existing = BusinessDateClose::query()->where('property_id', $property->id)->whereDate('business_date', $date)->first();
            if ($existing?->completed_at !== null) {
                return $existing;
            }
            if (BusinessDate::of($property) !== $date) {
                throw ValidationException::withMessages(['business_date' => __('Only the current business date can be closed.')]);
            }
            $close = $existing ?? BusinessDateClose::query()->create(['business_date' => $date, 'actor_id' => $actor?->id]);
            $reservations = Reservation::query()->where('property_id', $property->id)
                ->where('status', ReservationStatus::CheckedIn)->whereDate('arrival', '<=', $date)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                app(PostRoomCharge::class)->handle($actor, $reservation, $date);
            }
            $this->markStayovers($actor, $property, $property->business_date->addDay()->toDateString());
            $property->update(['business_date' => $property->business_date->addDay()->toDateString()]);
            $close->update(['completed_at' => now()]);
            Audit::record($actor, 'closed', $close, 'Business date closed', ['business_date' => $date]);

            return $close;
        }, attempts: 3));
    }

    private function markStayovers(?User $actor, Property $property, string $nextDate): void
    {
        if (! $property->stayover_service_on_close) {
            return;
        }
        $statuses = RoomStatus::query()->with('room')->where('property_id', $property->id)->where('status', HousekeepingStatus::Occupied)->get();
        foreach ($statuses as $status) {
            app(MarkStayoverService::class)->handle($actor, $status->room, $nextDate);
        }
    }
}
