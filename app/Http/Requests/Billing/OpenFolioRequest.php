<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\Folio;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class OpenFolioRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [Folio::class, $this->route('reservation')]) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['currency' => ['required', 'string', 'size:3']];
    }
}
