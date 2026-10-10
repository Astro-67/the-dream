<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Reservation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $arrival = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $this->string('arrival')->value()) ?: null, report: false);

        return [
            'arrival' => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival', ...($arrival ? ['before_or_equal:'.$arrival->addDays(365)->toDateString()] : [])],
            'adults' => ['required', 'integer', 'between:1,20'],
            'children' => ['required', 'integer', 'between:0,20'],
            'room_type_id' => ['required', 'string', Rule::in(RoomType::query()->pluck('id')->all())],
            'rate_plan_id' => ['required', 'string', Rule::in(RatePlan::query()->whereNull('deactivated_at')->whereHas('roomTypes', fn ($query) => $query->whereKey($this->string('room_type_id')->value()))->pluck('id')->all())],
            'booker_id' => [$this->isMethod('GET') ? 'nullable' : 'required', 'string', Rule::in(Booker::query()->pluck('id')->all())],
            'source' => [$this->isMethod('GET') ? 'nullable' : 'required', Rule::in(['direct', 'walk-in', 'phone'])],
            'room_id' => ['nullable', 'string', Rule::in(Room::query()->pluck('id')->all())],
            'payment_collector' => ['sometimes', Rule::in(['property', 'channel'])],
            'restriction_override_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
