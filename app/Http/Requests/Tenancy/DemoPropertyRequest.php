<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class DemoPropertyRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        if ($this->routeIs('properties.demo.store')) {
            return $this->user()?->can('create', Property::class) ?? false;
        }

        $property = $this->route('property');

        return $property instanceof Property && ($this->user()?->can('delete', $property) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
