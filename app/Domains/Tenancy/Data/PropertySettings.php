<?php

namespace App\Domains\Tenancy\Data;

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Rates\Enums\ZraTaxCategory;
use Illuminate\Validation\Rule;

final class PropertySettings
{
    public const array DEFAULTS = [
        'front_desk_settings' => [
            'book_ahead_days' => 365, 'minimum_notice_hours' => 0, 'same_day_cutoff_time' => null,
            'auto_cancel_unpaid_hours' => 24, 'free_cancellation_hours' => 72,
            'default_check_in_time' => '14:00', 'default_check_out_time' => '11:00',
            'walk_ins_enabled' => true, 'walk_in_requires_id' => true, 'maximum_overbooking_percentage' => 0,
            'minimum_stay' => 1, 'maximum_stay' => 90, 'passport_required_for_foreign_guests' => true,
            'automatic_checkout_enabled' => false, 'automatic_checkout_time' => '11:59',
            'automatic_checkout_include_unpaid' => true, 'automatic_checkout_include_overstays' => false,
            'automatic_checkout_summary_email' => null,
        ],
        'tax_settings' => [
            'zra_tax_category' => 'vat', 'tax_label' => 'VAT', 'tax_rate' => 15,
            'service_charge_enabled' => false, 'service_charge_rate' => 10,
            'show_breakdown_on_bill' => true, 'show_breakdown_on_receipt' => true,
        ],
        'payment_settings' => [
            'accepted_methods' => RecordPayment::METHODS, 'block_checkout_if_unpaid' => true,
            'manager_override' => true, 'deposit_percentage' => 0, 'payment_due_days' => 0,
        ],
    ];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::DEFAULTS as $group => $fields) {
            $rules[$group] = ['sometimes', 'array:'.implode(',', array_keys($fields))];
            foreach ($fields as $field => $default) {
                $rules["$group.$field"] = match (true) {
                    is_bool($default) => ['sometimes', 'boolean'],
                    str_ends_with($field, '_time') => ['sometimes', ...($default === null ? ['nullable'] : []), 'date_format:H:i'],
                    $field === 'automatic_checkout_summary_email' => ['sometimes', 'nullable', 'email', 'max:255'],
                    $field === 'accepted_methods' => ['sometimes', 'array', 'min:1'],
                    $field === 'zra_tax_category' => ['sometimes', Rule::enum(ZraTaxCategory::class)],
                    $field === 'tax_label' => ['sometimes', 'required', 'string', 'max:64'],
                    str_contains($field, 'percentage') || str_ends_with($field, '_rate') => ['sometimes', 'numeric', 'decimal:0,2', 'between:0,100'],
                    default => ['sometimes', 'integer', in_array($field, ['minimum_stay', 'maximum_stay'], true) ? 'min:1' : 'min:0', 'max:65535'],
                };
            }
        }
        $rules['payment_settings.accepted_methods.*'] = ['required', 'distinct', Rule::in(RecordPayment::METHODS)];

        return $rules;
    }
}
