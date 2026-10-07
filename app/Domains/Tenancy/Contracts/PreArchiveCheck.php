<?php

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Models\Property;

/**
 * A condition a property must meet before it can be archived, such as "no
 * future bookings" or "no open balances". ArchiveProperty runs every check
 * tagged PreArchiveCheck::TAG in the container, inside the property's tenant
 * context, and refuses with the reasons of all that fail.
 *
 * Register one from the owning domain's service provider:
 *
 *     $this->app->tag([NoFutureBookings::class], PreArchiveCheck::TAG);
 */
interface PreArchiveCheck
{
    public const TAG = 'tenancy.pre-archive-checks';

    /**
     * Null when the property may be archived; otherwise one sentence telling
     * the owner what stops it ("Stone Town Inn has 3 future bookings.").
     */
    public function blockingReason(Property $property): ?string;
}
