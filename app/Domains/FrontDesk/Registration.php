<?php

namespace App\Domains\FrontDesk;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** A guest is registered when one identity document fills every field the jurisdiction requires (ADR 0014). */
class Registration
{
    /**
     * Refuses on `guests` while any guest is unregistered, or when fields are required and there are no guests.
     *
     * @param  Collection<int, Guest>  $guests
     */
    public static function ensure(Property $property, Collection $guests): void
    {
        $fields = JurisdictionRules::requiredRegistrationFields($property);
        if ($fields === [] && ! $property->settings()['front_desk_settings']['passport_required_for_foreign_guests']) {
            return;
        }
        $guests->loadMissing('documents');
        $missing = $guests->isEmpty() && $fields !== [] ? [__('Add a guest')] : $guests
            ->reject(function (Guest $guest) use ($fields, $property): bool {
                if ($fields === [] && ! JurisdictionRules::requiresPassport($property, $guest->getAttribute('nationality'))) {
                    return true;
                }

                return $guest->documents->contains(function (IdentityDocument $document) use ($fields, $property, $guest): bool {
                    $passport = JurisdictionRules::requiresPassport($property, $guest->getAttribute('nationality') ?? $document->getAttribute('nationality'));

                    return collect($fields)->every(fn (string $field) => filled($document->getAttribute($field)))
                        && (! $passport || ($document->getAttribute('type') === 'passport' && filled($document->getAttribute('number'))));
                });
            })
            ->map->name->values()->all();
        if ($missing !== []) {
            throw ValidationException::withMessages(['guests' => __('Registration incomplete (:fields required): :guests.', ['fields' => implode(', ', $fields), 'guests' => implode(', ', $missing)])]);
        }
    }
}
