<?php

namespace Tests\Support;

/**
 * Submissions of the room type and room forms.
 */
final class InventoryForm
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function roomType(array $overrides = []): array
    {
        return [
            'name' => 'Deluxe Double',
            'code' => 'DLX',
            'description' => 'Sea view, king bed',
            'base_occupancy' => 2,
            'max_adults' => 2,
            'max_children' => 1,
            'max_occupancy' => 3,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function room(string $roomTypeId, array $overrides = []): array
    {
        return [
            'number' => '101',
            'floor' => '1',
            'room_type_id' => $roomTypeId,
            ...$overrides,
        ];
    }
}
