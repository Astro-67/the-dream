<?php
namespace App\Http\Requests\Guests;
use App\Domains\Guests\Models\Guest;
use Illuminate\Foundation\Http\FormRequest;
class GuestRequest extends FormRequest {
    public function authorize(): bool {
        $guest = $this->route('guest');
        return $guest instanceof Guest ? $this->user()?->can('update', $guest) ?? false : $this->user()?->can('create', Guest::class) ?? false;
    }
    /** @return array<string, array<string>> */
    public function rules(): array {
        return [
            'documents' => [\Illuminate\Validation\Rule::prohibitedIf(! \App\Support\Tenancy\Access::allows($this->user(), \App\Domains\Tenancy\Enums\OrganizationPermission::GuestIdentityView, \App\Support\Tenancy\Tenancy::property())), 'sometimes', 'array', 'max:10'],
            'documents.*' => ['array:type,number,nationality,issuing_country,expiry'],
            'documents.*.type' => ['required', 'string', 'max:50'],
            'documents.*.number' => ['required', 'string', 'max:255'],
            'documents.*.nationality' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'documents.*.issuing_country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'documents.*.expiry' => ['nullable', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'preferences' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
    /** @return array<string, mixed> */
    public function details(): array { return $this->safe()->only([...Guest::FIELDS, 'documents']); }
}
