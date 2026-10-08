<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Models\Property;

interface RestoreIdentityBoundaryAdapter
{
    /** True only when the provider attests full identity coverage for this property. */
    public function identityCoverageComplete(): bool;

    /** @return array<string, array{subject_type: mixed, subject_id: mixed, highest_sequence: mixed, evidence: mixed}> */
    public function identityBoundaries(Property $property): array;
}
