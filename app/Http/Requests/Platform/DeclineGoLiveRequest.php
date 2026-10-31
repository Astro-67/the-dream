<?php

namespace App\Http\Requests\Platform;

use App\Domains\Tenancy\Models\Property;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class DeclineGoLiveRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('declineGoLive', Property::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
