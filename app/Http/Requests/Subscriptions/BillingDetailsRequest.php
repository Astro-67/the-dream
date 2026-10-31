<?php

namespace App\Http\Requests\Subscriptions;

use App\Domains\Subscriptions\Models\BillingDetails;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class BillingDetailsRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('manage', BillingDetails::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bank = 'required_if:accounts.*.type,bank|prohibited_unless:accounts.*.type,bank';

        return [
            'business_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'tin' => ['required', 'string', 'max:50'],
            'vrn' => ['nullable', 'string', 'max:50'],
            'accounts' => ['array', 'max:20'],
            'accounts.*.type' => ['required', 'in:bank,mobile_money'],
            'accounts.*.bank' => [$bank, 'nullable', 'string', 'max:100'],
            'accounts.*.branch' => [$bank, 'nullable', 'string', 'max:100'],
            'accounts.*.swift' => ['prohibited_unless:accounts.*.type,bank', 'nullable', 'string', 'max:11'],
            'accounts.*.provider' => ['required_if:accounts.*.type,mobile_money', 'prohibited_unless:accounts.*.type,mobile_money', 'nullable', 'string', 'max:100'],
            'accounts.*.account_name' => ['required', 'string', 'max:255'],
            'accounts.*.account_number' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * Each account keeps only its type's fields, in a fixed order, so the snapshot is stable.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $details = $this->safe()->except('accounts');
        $details['accounts'] = array_map(fn (array $account) => $account['type'] === 'bank'
            ? ['type' => 'bank', 'bank' => $account['bank'], 'branch' => $account['branch'], 'swift' => $account['swift'] ?? null, 'account_name' => $account['account_name'], 'account_number' => $account['account_number']]
            : ['type' => 'mobile_money', 'provider' => $account['provider'], 'account_name' => $account['account_name'], 'account_number' => $account['account_number']],
            $this->validated('accounts', []));

        return $details;
    }
}
