<?php

namespace App\Domains\Onboarding;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayPricing;
use App\Domains\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReservationImportCsv
{
    public const COLUMNS = ['source_reference', 'room_type_code', 'rate_plan_code', 'arrival', 'departure', 'adults', 'children', 'booker_name', 'booker_email', 'guest_name', 'guest_email', 'status', 'restriction_override_reason'];

    /** @return list<array<string, mixed>> */
    public function rows(UploadedFile $file): array
    {
        $stream = fopen($file->getPathname(), 'r');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => __('The CSV could not be read.')]);
        }
        try {
            $header = fgetcsv($stream, escape: '');
            if ($header !== false) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
            }
            if ($header !== self::COLUMNS) {
                throw ValidationException::withMessages(['file' => __('Use the downloaded template with its columns in the same order.')]);
            }
            $rows = [];
            $references = [];
            $number = 1;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                $number++;
                if ($values === [null]) {
                    continue;
                }
                if (count($rows) >= 1000) {
                    throw ValidationException::withMessages(['file' => __('Upload at most 1,000 reservations at a time.')]);
                }
                $row = $this->row($values, $number);
                $reference = $row['source_reference'];
                if ($reference !== null && (isset($references[$reference]) || Reservation::query()->where('source_reference', $reference)->exists())) {
                    $row['status'] = 'duplicate';
                    $row['validated_data'] = null;
                    $row['errors']['source_reference'] = [__('This source reference already exists at this property or appears earlier in the CSV.')];
                }
                if ($reference !== null) {
                    $references[$reference] = true;
                }
                $rows[] = $row;
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => __('Add at least one reservation to the CSV.')]);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /** @param list<string|null> $values
     * @return array<string, mixed>
     */
    private function row(array $values, int $number): array
    {
        $raw = count($values) === count(self::COLUMNS)
            ? array_combine(self::COLUMNS, array_map(fn ($value) => trim($value ?? ''), $values))
            : $values;
        $reference = $raw['source_reference'] ?? '';
        $result = ['row_number' => $number, 'source_reference' => $reference !== '' && mb_strlen($reference) <= 255 ? $reference : null, 'status' => 'invalid', 'raw_data' => $raw, 'validated_data' => null, 'errors' => []];
        if (count($values) !== count(self::COLUMNS)) {
            return [...$result, 'errors' => ['row' => [__('The row must have the same number of columns as the template.')]]];
        }
        try {
            $data = Validator::make($raw, [
                'source_reference' => ['required', 'string', 'max:255'],
                'room_type_code' => ['required', 'string', 'max:30'],
                'rate_plan_code' => ['required', 'string', 'max:30'],
                'arrival' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.BusinessDate::current()],
                'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
                'adults' => ['required', 'integer', 'between:1,20'],
                'children' => ['required', 'integer', 'between:0,20'],
                'booker_name' => ['required', 'string', 'max:255'],
                'booker_email' => ['nullable', 'email', 'max:255'],
                'guest_name' => ['required', 'string', 'max:255'],
                'guest_email' => ['nullable', 'email', 'max:255'],
                'status' => ['required', 'in:confirmed'],
                'restriction_override_reason' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            if ($data['departure'] > CarbonImmutable::parse($data['arrival'])->addDays(365)->toDateString()) {
                throw ValidationException::withMessages(['departure' => __('A reservation cannot exceed 365 nights.')]);
            }
            $type = RoomType::query()->where('code', $data['room_type_code'])->first();
            $plan = RatePlan::query()->where('code', $data['rate_plan_code'])->whereNull('deactivated_at')
                ->whereHas('roomTypes', fn ($query) => $query->whereKey($type?->id))->first();
            if ($type === null || $plan === null) {
                throw ValidationException::withMessages([
                    ...($type === null ? ['room_type_code' => __('Choose a room type belonging to this property.')] : []),
                    ...($plan === null ? ['rate_plan_code' => __('Choose an active rate plan for this room type and property.')] : []),
                ]);
            }
            $quote = app(StayPricing::class)->quote($plan, $type, $data['arrival'], $data['departure'], (int) $data['adults'], (int) $data['children']);
            if ($quote->unquotable !== null) {
                throw ValidationException::withMessages(['rate_plan_code' => $quote->unquotable]);
            }

            return [...$result, 'source_reference' => $data['source_reference'], 'status' => 'valid', 'validated_data' => [
                'reservation' => [
                    'source_reference' => $data['source_reference'], 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
                    'arrival' => $data['arrival'], 'departure' => $data['departure'], 'adults' => (int) $data['adults'], 'children' => (int) $data['children'],
                    'source' => 'import', 'payment_collector' => 'property', 'restriction_override_reason' => $data['restriction_override_reason'] ?: null,
                ],
                'booker' => ['name' => $data['booker_name'], 'email' => $data['booker_email'] ?: null],
                'guest' => ['name' => $data['guest_name'], 'email' => $data['guest_email'] ?: null],
                'quote' => ['currency' => $quote->currency->value, 'nights' => $quote->nights],
            ]];
        } catch (ValidationException $error) {
            return [...$result, 'errors' => $error->errors()];
        }
    }
}
