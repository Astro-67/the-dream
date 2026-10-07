<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\Tenancy\Concerns\ValidatesMembershipGrant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Transferring ownership to a member. The role and properties fields are what
 * the previous owner (the one submitting) keeps after the transfer.
 */
class TransferOwnershipRequest extends FormRequest
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
