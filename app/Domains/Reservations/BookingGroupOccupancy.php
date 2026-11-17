<?php

namespace App\Domains\Reservations;

use App\Domains\Inventory\Models\RoomType;
use Illuminate\Validation\ValidationException;

class BookingGroupOccupancy
{
    /**
     * The booked room lines; a booking without lines is one line of the top-level room type.
     *
     * @param  array<string, mixed>  $details
     * @return list<array{room_type_id: ?string, rate_plan_id: ?string, rooms: int, room_ids: list<?string>}>
     */
    public static function lines(array $details): array
    {
        $lines = $details['lines'] ?? [[
            'room_type_id' => $details['room_type_id'] ?? null, 'rate_plan_id' => $details['rate_plan_id'] ?? null,
            'rooms' => $details['rooms'] ?? 1, 'room_ids' => [$details['room_id'] ?? null],
        ]];

        return array_map(fn (array $line) => [
            'room_type_id' => $line['room_type_id'] ?? null, 'rate_plan_id' => $line['rate_plan_id'] ?? null,
            'rooms' => (int) ($line['rooms'] ?? 1), 'room_ids' => array_values($line['room_ids'] ?? []),
        ], array_values($lines));
    }

    /**
     * @param  list<RoomType>  $types  one entry per booked room
     * @param  array<string, mixed>  $details
     * @return list<array{adults: int, children: int}>
     */
    public function forRooms(array $types, array $details): array
    {
        $rooms = count($types);
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
        $capacity = array_map(fn (RoomType $type) => min($type->max_adults, $type->max_occupancy), $types);
        if ($adults > array_sum($capacity) || $children < 0) {
            throw ValidationException::withMessages(['rooms' => __('The guests do not fit in the selected rooms. Add rooms or choose another room type.')]);
        }
        // One adult per room, then the rest round-robin into rooms with space left.
        $roomAdults = array_fill(0, $rooms, 1);
        for ($left = $adults - $rooms; $left > 0;) {
            foreach ($capacity as $index => $max) {
                if ($left > 0 && $roomAdults[$index] < $max) {
                    $roomAdults[$index]++;
                    $left--;
                }
            }
        }
        $occupancies = [];
        foreach ($types as $index => $type) {
            $roomChildren = max(0, min($children, $type->max_children, $type->max_occupancy - $roomAdults[$index]));
            $occupancies[] = ['adults' => $roomAdults[$index], 'children' => $roomChildren];
            $children -= $roomChildren;
        }
        if ($children > 0) {
            throw ValidationException::withMessages(['rooms' => __('The guests do not fit in the selected rooms. Add rooms or choose another room type.')]);
        }

        return $occupancies;
    }
}
