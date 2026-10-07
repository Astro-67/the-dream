<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\Tenancy\Concerns\ValidatesMembershipGrant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A member's new role and properties. Who may change them is MembershipPolicy's
 * call; the owner rules are ChangeMembership's.
 */
class UpdateMembershipRequest extends FormRequest
{
    use ValidatesMembershipGrant;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->grantRules();
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
}
