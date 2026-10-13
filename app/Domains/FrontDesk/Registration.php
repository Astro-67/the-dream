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
        if ($fields === []) {
            return;
        }
        $guests->loadMissing('documents');
        $missing = $guests->isEmpty() ? [__('Add a guest')] : $guests
            ->reject(fn (Guest $guest) => $guest->documents->contains(fn (IdentityDocument $document) => collect($fields)->every(fn (string $field) => filled($document->getAttribute($field)))))
            ->map->name->values()->all();
        if ($missing !== []) {
            throw ValidationException::withMessages(['guests' => __('Registration incomplete (:fields required): :guests.', ['fields' => implode(', ', $fields), 'guests' => implode(', ', $missing)])]);
        }
    }
}
