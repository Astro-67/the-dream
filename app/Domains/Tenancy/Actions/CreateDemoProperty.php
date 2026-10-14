<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates a demo property (GLOSSARY: demo property) shaped like the property the
 * owner works in, and fills it with sample rooms, rates and reservations inside
 * its own tenant context. It stays in setup: GoLive refuses demo properties.
 */
class CreateDemoProperty
{
    public function __construct(private readonly CreateProperty $createProperty) {}

    public function handle(User $actor, Property $template): Property
    {
        return DB::transaction(function () use ($actor, $template) {
            $property = $this->createProperty->handle($actor, new PropertyDetails(
                name: __('Demo: :name', ['name' => $template->name]),
                type: $template->property_type,
                addressLine: $template->address_line,
                city: $template->city,
                region: $template->region,
                postalCode: $template->postal_code,
                phone: $template->phone,
                email: $template->email,
                timezone: $template->timezone,
                currency: $template->currency,
                jurisdictionProfile: $template->jurisdiction_profile,
            ), demo: true);

            Tenancy::within(TenantContext::forProperty($property), fn () => $this->seed($actor, $property));

            return $property;
        });
    }

    private function seed(User $actor, Property $property): void
    {
        $today = $property->business_date;
        $plan = app(CreateRatePlan::class)->handle($actor, ['name' => __('Room only'), 'code' => 'RO', 'currency' => $property->currency->value]);
        $types = [];

        foreach ([['Standard Double', 'STD', 2, 0, ['101', '102', '103'], 8000000], ['Family Suite', 'FAM', 2, 2, ['201', '202'], 15000000]] as [$name, $code, $adults, $children, $numbers, $price]) {
            $type = app(CreateRoomType::class)->handle($actor, [
                'name' => $name, 'code' => $code, 'description' => __('Sample room type'),
                'base_occupancy' => $adults, 'max_adults' => $adults, 'max_children' => $children, 'max_occupancy' => $adults + $children,
            ]);
            foreach ($numbers as $number) {
                app(CreateRoom::class)->handle($actor, ['number' => $number, 'floor' => $number[0], 'room_type_id' => $type->id]);
            }
            $plan->roomTypes()->attach($type->id);
            app(SetRatePrices::class)->handle($actor, $plan, [
                'room_type_id' => $type->id, 'from' => $today->toDateString(), 'to' => $today->addDays(60)->toDateString(),
                'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => $price, 'extra_adult' => 0, 'extra_child' => (int) ($price / 4),
            ]);
            $types[] = $type;
        }

        foreach ([['Amina Juma', 0, 2, 0, 2], ['Lukas Weber', 3, 7, 1, 2], ['Grace Mwangi', 10, 12, 0, 1]] as [$guest, $from, $to, $type, $adults]) {
            $booker = app(CreateBooker::class)->handle($actor, ['name' => $guest]);
            app(CreateReservation::class)->handle($actor, [
                'room_type_id' => $types[$type]->id, 'rate_plan_id' => $plan->id, 'booker_id' => $booker->id,
                'arrival' => $today->addDays($from)->toDateString(), 'departure' => $today->addDays($to)->toDateString(),
                'adults' => $adults, 'children' => 0, 'source' => 'direct',
            ]);
        }
    }
}
