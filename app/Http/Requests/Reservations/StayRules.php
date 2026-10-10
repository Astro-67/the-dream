<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

trait StayRules
{
    /** @return array<string, mixed> */
    protected function stayRules(): array
    {
        $arrival = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $this->string('arrival')->value()) ?: null, report: false);

        return [
            'arrival' => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival', ...($arrival ? ['before_or_equal:'.$arrival->addDays(365)->toDateString()] : [])],
            'adults' => ['required', 'integer', 'between:1,20'],
            'children' => ['required', 'integer', 'between:0,20'],
            'room_type_id' => ['required', 'string', Rule::in(RoomType::query()->pluck('id')->all())],
            'rate_plan_id' => ['required', 'string', Rule::in(RatePlan::query()->whereNull('deactivated_at')->whereHas('roomTypes', fn ($query) => $query->whereKey($this->string('room_type_id')->value()))->pluck('id')->all())],
            'room_id' => ['nullable', 'string', Rule::in(Room::query()->pluck('id')->all())],
            'restriction_override_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
