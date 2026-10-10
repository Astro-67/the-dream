<?php
namespace App\Http\Requests\Guests;
use App\Domains\Guests\Models\Booker;
use Illuminate\Foundation\Http\FormRequest;
class BookerRequest extends FormRequest {
    public function authorize(): bool {
        $booker = $this->route('booker');
        return $booker instanceof Booker ? $this->user()?->can('update', $booker) ?? false : $this->user()?->can('create', Booker::class) ?? false;
    }
    /** @return array<string, array<string>> */
    public function rules(): array {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'preferences' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
    /** @return array<string, mixed> */
    public function details(): array { return $this->safe()->only(Booker::FIELDS); }
}
