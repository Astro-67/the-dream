<?php

namespace App\Http\Requests\Reservations;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Guests\Models\Booker;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Enums\MealPlan;
use App\Domains\Reservations\Enums\TourOperatorPaysFor;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Requests\AuditsRefusals;
use App\Support\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateReservationRequest extends FormRequest
{
    use AuditsRefusals, PayingInstitutionRules, StayRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Reservation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('GET')) {
            $this->mergeIfMissing([
                'arrival' => BusinessDate::current(),
                'departure' => rescue(fn () => CarbonImmutable::parse($this->input('arrival', BusinessDate::current()))->addDay()->toDateString(), BusinessDate::current(), report: false),
                'adults' => 1,
                'children' => 0,
                'distribute_guests' => true,
            ]);
        }

        if (is_string($this->input('room_id')) && $this->filled('room_id')) {
            $room = Room::query()->find($this->input('room_id'));
            if ($room !== null) {
                $this->merge(['room_type_id' => $room->room_type_id]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->stayRules($this->isMethod('GET'));
        $rules['arrival'][] = 'after_or_equal:'.BusinessDate::current();
        if (! $this->isMethod('GET')) {
            $rules['room_type_id'][0] = $rules['rate_plan_id'][0] = 'required_without:lines';
        }

        return [
            ...$rules,
            'lead_guest' => [$this->isMethod('GET') ? 'nullable' : 'required_without:booker_id', 'array:first_name,last_name,email,phone,nationality,document_type,document_number'],
            'lead_guest.first_name' => ['required_with:lead_guest', 'string', 'max:120'],
            'lead_guest.last_name' => ['nullable', Rule::requiredIf(! $this->isMethod('GET') && $this->filled('lead_guest') && $this->input('source') !== 'walk-in'), 'string', 'max:120'],
            'lead_guest.email' => ['nullable', 'email', 'max:255'],
            'lead_guest.phone' => ['nullable', 'string', 'max:50'],
            'lead_guest.nationality' => ['nullable', Rule::in(array_keys(Countries::NAMES))],
            'lead_guest.document_type' => ['nullable', 'required_with:lead_guest.document_number', Rule::in(['passport', 'nida', 'zanid'])],
            'lead_guest.document_number' => ['nullable', 'required_with:lead_guest.document_type', 'string', 'max:255'],
            // Other adults staying in the room; children are never registered (VFMS has no child field).
            'guests' => ['sometimes', 'array', 'max:19'],
            'guests.*' => ['array:name,nationality,document_type,document_number'],
            'guests.*.name' => ['nullable', 'required_with:guests.*.document_type', 'string', 'max:255'],
            'guests.*.nationality' => ['nullable', Rule::in(array_keys(Countries::NAMES))],
            'guests.*.document_type' => ['nullable', 'required_with:guests.*.document_number', Rule::in(['passport', 'nida', 'zanid'])],
            'guests.*.document_number' => ['nullable', 'required_with:guests.*.document_type', 'string', 'max:255'],
            'meal_plan' => ['sometimes', Rule::enum(MealPlan::class)],
            'special_requests' => ['nullable', 'string', 'max:5000'],
            'nightly_rate' => ['nullable', 'integer', 'between:0,1000000000000'],
            'booked_through' => ['sometimes', Rule::in(['guest', 'tour-operator'])],
            'tour_operator_name' => ['exclude_unless:booked_through,tour-operator', 'required', 'string', 'max:255'],
            'tour_operator_registration' => ['exclude_unless:booked_through,tour-operator', 'required', 'string', 'max:255'],
            'tour_operator_pays_for' => ['exclude_unless:booked_through,tour-operator', 'required', Rule::enum(TourOperatorPaysFor::class)],
            ...$this->payingInstitutionRules(),
            'rooms' => ['sometimes', 'integer', 'between:1,20'],
            // Several room types in one booking group; the action checks each plan sells its type.
            'lines' => ['sometimes', 'array', 'between:1,20'],
            'lines.*' => ['array:room_type_id,rate_plan_id,rooms,room_ids'],
            'lines.*.room_type_id' => [$this->isMethod('GET') ? 'nullable' : 'required', 'string', Rule::in(RoomType::query()->pluck('id')->all())],
            'lines.*.rate_plan_id' => [$this->isMethod('GET') ? 'nullable' : 'required', 'string', Rule::in(RatePlan::query()->whereNull('deactivated_at')->pluck('id')->all())],
            'lines.*.rooms' => ['required', 'integer', 'between:1,20'],
            'lines.*.room_ids' => ['sometimes', 'array', 'max:20'],
            'lines.*.room_ids.*' => ['nullable', 'string', Rule::in(Room::query()->pluck('id')->all())],
            'distribute_guests' => ['sometimes', 'boolean'],
            'booker_id' => ['nullable', 'string', Rule::in(Booker::query()->pluck('id')->all())],
            'source' => [$this->isMethod('GET') ? 'nullable' : 'required', Rule::in(['direct', 'walk-in', 'phone', 'whatsapp', 'email', 'website', 'outside-integration'])],
            'channel_reference' => ['exclude_unless:source,outside-integration', $this->isMethod('GET') ? 'nullable' : 'required', 'string', 'max:255'],
            'outside_integration_reason' => ['exclude_unless:source,outside-integration', $this->isMethod('GET') ? 'nullable' : 'required', 'string', 'max:2000'],
            'payment_collector' => ['sometimes', Rule::in(['property', 'channel'])],
            'channel_collected_amount' => ['exclude_unless:payment_collector,channel', $this->isMethod('GET') ? 'nullable' : 'required', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }
}
