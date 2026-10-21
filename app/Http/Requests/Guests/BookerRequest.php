<?php

namespace App\Http\Requests\Guests;

use App\Domains\Guests\Models\Booker;
use App\Http\Requests\AuditsRefusals;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookerRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $booker = $this->route('booker');

        return $booker instanceof Booker ? $this->user()?->can('update', $booker) ?? false : $this->user()?->can('create', Booker::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', Rule::in(array_keys(Countries::NAMES))],
            'preferences' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->safe()->only(Booker::FIELDS);
    }
}
