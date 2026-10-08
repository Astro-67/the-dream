<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Models\Property;

interface RestoreIdentityBoundaryAdapter
{
    /** True only when the provider can attest its response covers every identity in the requested property scope. */
    public function identityCoverageComplete(): bool;

    /** @return array<string, array{subject_type: string, subject_id: string, highest_sequence: int, evidence: string}> */} to=functions.Edit
    public function identityBoundaries(Property $property): array;
}
