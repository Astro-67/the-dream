<?php

namespace App\Http\Requests\Onboarding;

use App\Domains\Onboarding\Models\ImportJob;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class ImportRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('create', ImportJob::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:2048']];
    }
}
