<?php

namespace App\Domains\Inventory\Exceptions;

use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A change would leave a room type with fewer sellable rooms than are already
 * sold on some dates. The message names those dates.
 */
final class InventoryRefused extends RuntimeException
{
    private const NAMED_DATES = 5;

    /**
     * @param  non-empty-list<string>  $dates
     */
    public static function belowSold(RoomType $roomType, array $dates): self
    {
        $more = count($dates) - self::NAMED_DATES;

        return new self(__(':type would have fewer sellable rooms than are already sold on :dates:more.', [
            'type' => $roomType->code,
            'dates' => implode(', ', array_slice($dates, 0, self::NAMED_DATES)),
            'more' => $more > 0 ? __(' and :count more dates', ['count' => $more]) : '',
        ]));
    }

    /**
     * Runs $work and, when it is refused, audits the refusal after its transaction rolled back.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function audited(?User $actor, Model $subject, string $description, Closure $work): mixed
    {
        try {
            return $work();
        } catch (InventoryRefused $refused) {
            Audit::record($actor, 'refused', $subject, $description, ['result' => 'refused', 'reason' => $refused->getMessage()]);

            throw $refused;
        }
    }
}
