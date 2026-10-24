<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\IdentityDocument;

/**
 * protects: encrypted document numbers have a keyed, normalized search value that stays private.
 * fails_when: whitespace/case differ, the hash goes stale, or serialization exposes it.
 * no_claim: concurrent check-ins or application-key rotation.
 */
test('document search hashes follow normalized numbers on create and update without serialization', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    app()->forgetInstance('encrypter');
    [$owner, , $property] = organizationWithOwner();

    inTenant($property, function () use ($owner) {
        $guest = app(CreateGuest::class)->handle($owner, ['name' => 'Registered Guest', 'documents' => [['number' => " p 123\t4567\n"]]]);
        $document = $guest->documents()->sole();
        expect($document->getAttribute('number_hash'))->toBe('dd0277d574e5fcb1a7657af3a617c8a0706fa1e1e1d156bbb060a27093e47d55')
            ->and($document->toArray())->not->toHaveKey('number_hash');

        $document->update(['number' => 'CHANGED']);
        $changed = $document->fresh()->getAttribute('number_hash');
        $same = $guest->documents()->create(['number' => ' changed ']);
        expect($changed)->toBe($same->getAttribute('number_hash'))
            ->not->toBe('dd0277d574e5fcb1a7657af3a617c8a0706fa1e1e1d156bbb060a27093e47d55');

        $document->update(['number' => null]);
        expect($document->fresh()->getAttribute('number_hash'))->toBeNull();
        $same->update(['number' => " \t\n"]);
        expect($same->fresh()->getAttribute('number_hash'))->toBeNull()
            ->and(IdentityDocument::query()->count())->toBe(2);
    });
});
