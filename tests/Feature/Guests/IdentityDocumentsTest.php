<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('identity documents are encrypted and excluded from audit and automatic serialization', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $document = ['type' => 'passport', 'number' => 'P-SECRET-90210', 'nationality' => 'TZ', 'issuing_country' => 'TZ', 'expiry' => '2030-12-31'];
    $details = ['_property' => $t->a->property->id, 'name' => 'Amina Said', 'documents' => [$document]];
    $this->post(route('guests.store'), $details)->assertSessionHasNoErrors();
    $guest = inTenant($t->a->property, fn () => Guest::query()->sole());
    $identity = inTenant($t->a->property, fn () => IdentityDocument::query()->sole());
    foreach ($document as $field => $value) {
        $ciphertext = DB::table('identity_documents')->where('id', $identity->id)->value($field);
        expect($ciphertext)->not->toBe($value);
        expect(Illuminate\Support\Facades\Crypt::decryptString($ciphertext))->toBe($value);
    }
    expect($identity->toArray())->not->toHaveKeys(array_keys($document));
    $this->get(route('guests.show', $guest))->assertInertia(fn (Assert $page) => $page->where('documents.0.number', 'P-SECRET-90210'));
    $this->put(route('guests.update', $guest), [...$details, 'documents' => [[...$document, 'number' => 'P-SECRET-UPDATED']]])->assertSessionHasNoErrors();
    $this->get(route('guests.edit', $guest))->assertInertia(fn (Assert $page) => $page->where('documents.0.number', 'P-SECRET-UPDATED'));
    expect(Activity::query()->get()->toJson())->not->toContain('P-SECRET-90210')->not->toContain('P-SECRET-UPDATED');
});
