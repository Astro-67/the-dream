<?php

namespace App\Http\Requests\Housekeeping;

use App\Domains\Housekeeping\Models\Assignment;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class AssignmentOperationRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof Assignment && ($this->user()?->can($this->isMethod('DELETE') ? 'delete' : 'complete', $assignment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
