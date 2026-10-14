<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class GoLiveRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $property = $this->route('property');
        $ability = $this->routeIs('properties.taxes-reviewed') ? 'reviewTaxes' : 'goLive';

        return $property instanceof Property && ($this->user()?->can($ability, $property) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
