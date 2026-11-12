<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

/** A property's logo and accent colour (properties.branding.update). A blank colour clears it. */
class PropertyBrandingRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $property = $this->route('property');

        return $property instanceof Property && ($this->user()?->can('manageBranding', $property) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // `image` refuses SVG, and `dimensions` decodes the file, so only real raster images pass.
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=4000,max_height=4000'],
            'remove_logo' => ['boolean'],
            'accent_colour' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['accent_colour.regex' => __('Choose a colour as a hex code, such as #1F6F5C.')];
    }
}
