<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class ReversePostingRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('reverse', $this->folio()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    public function posting(): Posting
    {
        /** @var Posting */
        return $this->route('posting');
    }

    public function folio(): Folio
    {
        return Folio::query()->findOrFail($this->posting()->folio_id);
    }
}
