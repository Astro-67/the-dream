<?php

namespace App\Domains\Tenancy\Exceptions;

use App\Domains\Tenancy\Models\Property;
use RuntimeException;

/**
 * A property can't be archived. $reasons holds every reason found, each a
 * sentence for the owner (the last-property rule and failed pre-archive checks).
 */
final class ArchiveRefused extends RuntimeException
{
    /**
     * @param  non-empty-list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function lastActiveProperty(Property $property): self
    {
        return new self([__(':name is the organization\'s only active property. Add or restore another property before archiving it.', ['name' => $property->name])]);
    }
}
