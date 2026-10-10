<?php

namespace App\Domains\Guests;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\IdentityDocument;
use Illuminate\Support\Str;

class GuestMatches
{
    /**
     * @param array<string, mixed> $details
     * @return list<array<string, mixed>>
     */
    public function find(array $details): array
    {
        $matches = [];
        $normalize = fn (mixed $value): string => Str::lower(trim((string) $value));
        $guests = Guest::query()->whereRaw('LOWER(TRIM(name)) = ?', [$normalize($details['name'])])->orderBy('id')->lazyById();
        foreach ($guests as $guest) {
            $contactMatches = collect(['email', 'phone'])->contains(fn (string $field) => ! empty($details[$field]) && $normalize($guest->getAttribute($field)) === $normalize($details[$field]));
            // ponytail: encrypted numbers are compared only for same-name property candidates; add a keyed blind index when this scan becomes costly.
            $documentMatches = ! empty($details['document_number']) && $guest->documents()->get()->contains(fn (IdentityDocument $document) => $normalize($document->getAttribute('number')) === $normalize($details['document_number']));
            if ($contactMatches || $documentMatches) {
                $matches[] = ['id' => $guest->id, ...$guest->only(['name', 'email', 'phone'])];
                if (count($matches) === 20) {
                    break;
                }
            }
        }

        return $matches;
    }
}
