<?php

namespace App\Domains\Reservations;

use App\Domains\Inventory\Models\RoomType;
use Illuminate\Validation\ValidationException;

class BookingGroupOccupancy
{
    /**
     * @param  array<string, mixed>  $details
     * @return list<array{adults: int, children: int}>
     */
    public function forRooms(RoomType $type, array $details): array
    {
        $rooms = (int) ($details['rooms'] ?? 1);
        if ($rooms < 1 || $rooms > 20) {
            throw ValidationException::withMessages(['rooms' => __('Choose between 1 and 20 rooms.')]);
        }
        $adults = (int) $details['adults'];
        $children = (int) $details['children'];
        if (empty($details['distribute_guests'])) {
            return array_fill(0, $rooms, ['adults' => $adults, 'children' => $children]);
        }
        if ($adults < $rooms) {
            throw ValidationException::withMessages(['adults' => __('Each room needs at least one adult. Reduce rooms or add adults.')]);
        }
        if ($adults > $rooms * min($type->max_adults, $type->max_occupancy) || $children < 0) {
            throw ValidationException::withMessages(['rooms' => __('The guests do not fit in the selected rooms. Add rooms or choose another room type.')]);
        }
        $occupancies = [];
        for ($index = 0; $index < $rooms; $index++) {
            $roomAdults = intdiv($adults, $rooms) + ($index < $adults % $rooms ? 1 : 0);
            $roomChildren = min($children, $type->max_children, $type->max_occupancy - $roomAdults);
            $occupancies[] = ['adults' => $roomAdults, 'children' => $roomChildren];
            $children -= $roomChildren;
        }
        if ($children > 0) {
            throw ValidationException::withMessages(['rooms' => __('The guests do not fit in the selected rooms. Add rooms or choose another room type.')]);
        }

        return $occupancies;
    }
}
