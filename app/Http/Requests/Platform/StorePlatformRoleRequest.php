<?php

namespace App\Http\Requests\Platform;

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlatformRoleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', Rule::exists(User::class, 'email')],
            'role' => ['required', Rule::enum(PlatformRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.exists' => __('No user has this email address. They need to register first.'),
        ];
    }

    public function grantee(): User
    {
        return User::query()->where('email', $this->string('email')->toString())->firstOrFail();
    }

    public function role(): PlatformRole
    {
        return PlatformRole::from($this->string('role')->toString());
    }
}
