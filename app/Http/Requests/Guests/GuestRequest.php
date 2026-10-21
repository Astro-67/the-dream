<?php

namespace App\Http\Requests\Guests;

use App\Domains\Guests\Models\Guest;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Http\Requests\AuditsRefusals;
use App\Support\Countries;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuestRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $guest = $this->route('guest');

        return $guest instanceof Guest ? $this->user()?->can('update', $guest) ?? false : $this->user()?->can('create', Guest::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'documents' => [Access::allows($this->user(), OrganizationPermission::GuestIdentityView, Tenancy::property()) ? 'sometimes' : 'missing', 'sometimes', 'array', 'max:10'],
            'documents.*' => ['array:type,number,nationality,issuing_country,expiry'],
            'documents.*.type' => ['required', 'string', 'max:50'],
            'documents.*.number' => ['required', 'string', 'max:255'],
            'documents.*.nationality' => ['nullable', 'string', Rule::in(array_keys(Countries::NAMES))],
            'documents.*.issuing_country' => ['nullable', 'string', Rule::in(array_keys(Countries::NAMES))],
            'documents.*.expiry' => ['nullable', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', Rule::in(array_keys(Countries::NAMES))],
            'preferences' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->safe()->only([...Guest::FIELDS, 'documents']);
    }
}
