<?php

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Reservations\BookingGroupOccupancy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('total guest distribution respects adult child and combined room capacity', function () {
    $type = new RoomType(['max_adults' => 3, 'max_children' => 2, 'max_occupancy' => 3]);
    $allocation = new BookingGroupOccupancy;
    expect($allocation->forRooms([$type, $type], ['adults' => 3, 'children' => 3, 'distribute_guests' => true]))
        ->toBe([['adults' => 2, 'children' => 1], ['adults' => 1, 'children' => 2]]);
    expect(fn () => $allocation->forRooms([$type, $type], ['adults' => 4, 'children' => 3, 'distribute_guests' => true]))
        ->toThrow(ValidationException::class);
});

test('mixed room types each get guests within their own capacity', function () {
    $single = new RoomType(['max_adults' => 1, 'max_children' => 0, 'max_occupancy' => 1]);
    $family = new RoomType(['max_adults' => 3, 'max_children' => 2, 'max_occupancy' => 4]);
    $allocation = new BookingGroupOccupancy;
    expect($allocation->forRooms([$single, $family], ['adults' => 4, 'children' => 1, 'distribute_guests' => true]))
        ->toBe([['adults' => 1, 'children' => 0], ['adults' => 3, 'children' => 1]]);
    expect(fn () => $allocation->forRooms([$single, $family], ['adults' => 5, 'children' => 0, 'distribute_guests' => true]))
        ->toThrow(ValidationException::class);
});
