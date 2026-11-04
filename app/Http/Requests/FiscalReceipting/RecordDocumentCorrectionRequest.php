<?php

namespace App\Http\Requests\FiscalReceipting;

use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class RecordDocumentCorrectionRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof IssuedDocument && ($this->user()?->can('correct', $document) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['replacement_id' => ['required', 'ulid'], 'reason' => ['required', 'string', 'max:500'], 'confirmed' => ['accepted']];
    }
}
