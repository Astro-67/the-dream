<?php

namespace App\Domains\Tenancy\Data;

/**
 * One condition a property must meet before it may go live (JurisdictionRules::goLiveGates).
 */
final readonly class GoLiveGate
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $passed,
        /** Met by a field on the property form, which the checklist links to; other gates have their own checklist step. */
        public bool $onPropertyForm = false,
    ) {}
}
