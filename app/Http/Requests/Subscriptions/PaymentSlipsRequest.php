<?php

namespace App\Http\Requests\Subscriptions;

use App\Domains\Subscriptions\Models\Subscription;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class PaymentSlipsRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('view', Subscription::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
