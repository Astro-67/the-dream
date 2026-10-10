<?php

namespace App\Http\Requests\Guests;

use App\Domains\Guests\Models\Guest;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GuestMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $guest = $this->route('guest');
        $allowed = $guest instanceof Guest && ($this->user()?->can('merge', $guest) ?? false);
        if (! $allowed && $guest instanceof Guest) {
            Audit::record($this->user(), 'refused', $guest, 'Guest merge refused', ['result' => 'refused']);
        }

        return $allowed;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['duplicate_id' => ['required', 'ulid', function (string $attribute, mixed $value, Closure $fail): void {
            $guest = $this->route('guest');
            if (! $guest instanceof Guest || $value === $guest->id || ! Guest::query()->where('property_id', $guest->property_id)->whereKey($value)->exists()) {
                $fail(__('Choose a different guest at this property.'));
            }
        }]];
    }

    protected function failedValidation(Validator $validator): void
    {
        $guest = $this->route('guest');
        if ($guest instanceof Guest) {
            Audit::record($this->user(), 'refused', $guest, 'Guest merge refused', ['result' => 'refused']);
        }
        parent::failedValidation($validator);
    }
}
