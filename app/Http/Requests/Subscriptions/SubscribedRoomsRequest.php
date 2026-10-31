<?php

namespace App\Http\Requests\Subscriptions;

use App\Domains\Subscriptions\Models\Subscription;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

/** The owner changing their own subscribed rooms, or a platform administrator changing an organization's. */
class SubscribedRoomsRequest extends FormRequest
{
    use AuditsRefusals;

    public const RULES = ['required', 'integer', 'min:1', 'max:100000'];

    public function authorize(): bool
    {
        return $this->route('organization') !== null
            ? $this->user()?->can('manageRooms', Subscription::class) ?? false
            : $this->user()?->can('view', Subscription::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['rooms' => self::RULES];
    }
}
