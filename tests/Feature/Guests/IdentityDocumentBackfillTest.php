<?php

namespace Tests\Feature\Guests;

use App\Domains\Guests\Actions\CreateGuest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentityDocumentBackfillTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * protects: existing encrypted documents in every tenant get the same search hash as new ones.
     * fails_when: migration skips a tenant, hashes ciphertext, or changes encrypted source data.
     * no_claim: recovering documents encrypted with a missing key.
     */
    public function test_existing_encrypted_documents_are_backfilled_across_properties_and_organizations(): void
    {
        $tenants = \twoTenants();
        $documents = [];
        foreach ([$tenants->a->property, $tenants->a->otherProperty, $tenants->b->property] as $property) {
            $documents[] = \inTenant($property, function () use ($tenants) {
                $guest = app(CreateGuest::class)->handle($tenants->a->owner, ['name' => 'Existing Guest', 'documents' => [['number' => 'P1234567']]]);

                return $guest->documents()->sole();
            });
        }
        $expected = $documents[0]->getAttribute('number_hash');
        $migration = require database_path('migrations/2026_10_31_000109_guests_add_identity_document_number_hash.php');
        $migration->down();
        $ciphertext = Crypt::encryptString(" p 123\t4567\n");
        foreach ($documents as $document) {
            DB::table('identity_documents')->where('id', $document->id)->update(['number' => $ciphertext]);
        }
        $migration->up();
        foreach ($documents as $document) {
            $row = DB::table('identity_documents')->where('id', $document->id)->sole();
            $this->assertSame($expected, $row->number_hash);
            $this->assertSame($ciphertext, $row->number);
        }
    }
}
