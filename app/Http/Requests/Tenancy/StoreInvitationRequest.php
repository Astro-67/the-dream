<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Http\Requests\Tenancy\Concerns\ValidatesMembershipGrant;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The invite form, validated against the organization in the tenant context.
 * Whether the user may grant the role and properties is InvitationPolicy's call.
 */
class StoreInvitationRequest extends FormRequest
{
    use ValidatesMembershipGrant;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Invitation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', $this->notAlreadyInvitedOrMember(...)],
            ...$this->grantRules(),
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->grantMessages();
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->validateGrant(...)];
    }

    public function details(): InvitationDetails
    {
        return InvitationDetails::fromArray($this->validated());
    }

    private function notAlreadyInvitedOrMember(string $attribute, mixed $value, Closure $fail): void
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$value])->first();

        if ($user !== null && OrganizationMembership::query()->where('user_id', $user->getKey())->exists()) {
            $fail('This person is already a member of the organization.');

            return;
        }

        if (Invitation::query()->pending()->where('email', $value)->exists()) {
            $fail('This person already has a pending invitation. Revoke it to send a new one.');
        }
    }
}
