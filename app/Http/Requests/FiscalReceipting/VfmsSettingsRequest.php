<?php

namespace App\Http\Requests\FiscalReceipting;

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\Tenancy\Models\Property;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** VFMS credentials (properties.vfms.update) and the Sandbox/Live switch (properties.vfms.mode). */
class VfmsSettingsRequest extends FormRequest
{
    use AuditsRefusals;

    public const CONFIRMATION = 'LIVE';

    public function authorize(): bool
    {
        $property = $this->route('property');

        return $property instanceof Property && ($this->user()?->can('manageVfms', $property) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('properties.vfms.mode')) {
            return [
                'mode' => ['required', Rule::enum(VfmsMode::class)],
                'confirmation' => [Rule::requiredIf($this->input('mode') === VfmsMode::Live->value), 'nullable', 'string', Rule::in([self::CONFIRMATION])],
                'reason' => ['required', 'string', 'max:500'],
            ];
        }

        return [
            'integration_id' => ['required', 'string', 'max:100'],
            'registration_id' => ['required', 'string', 'max:100'],
            // Blank keeps the saved token: the form never receives it back.
            'token' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirmation.in' => __('Type :word to switch to Live.', ['word' => self::CONFIRMATION]), 'confirmation.required' => __('Type :word to switch to Live.', ['word' => self::CONFIRMATION])];
    }
}
