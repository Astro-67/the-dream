<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\Crypt;
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
        expect(Crypt::decryptString($ciphertext))->toBe($value);
    }
    expect($identity->toArray())->not->toHaveKeys(array_keys($document));
    $this->get(route('guests.show', $guest))->assertInertia(fn (Assert $page) => $page->where('documents.0.number', 'P-SECRET-90210'));
    $this->put(route('guests.update', $guest), [...$details, 'documents' => [[...$document, 'number' => 'P-SECRET-UPDATED']]])->assertSessionHasNoErrors();
    $this->get(route('guests.edit', $guest))->assertInertia(fn (Assert $page) => $page->where('documents.0.number', 'P-SECRET-UPDATED'));
    expect(Activity::query()->get()->toJson())->not->toContain('P-SECRET-90210')->not->toContain('P-SECRET-UPDATED');
});

test('every role sees only its permitted identity fields', function (OrganizationRole $role) {
    $t = twoTenants();
    $guest = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'Amina']));
    inTenant($t->a->property, fn () => $guest->documents()->create(['type' => 'passport', 'number' => 'SECRET-PASSPORT', 'nationality' => 'TZ', 'issuing_country' => 'TZ', 'expiry' => '2030-12-31']));
    $this->actingAs($t->a->member($role))->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $response = $this->get(route('guests.show', $guest));
    if ($role === OrganizationRole::Housekeeping) {
        $response->assertForbidden();
    } else {
        $allowed = in_array($role, [OrganizationRole::Owner, OrganizationRole::FrontDesk], true);
        $response->assertInertia(fn (Assert $page) => $page->where('canViewIdentity', $allowed)->where('documents.0.number', $allowed ? 'SECRET-PASSPORT' : '••••'));
        if (! $allowed) {
            $response->assertDontSee('SECRET-PASSPORT');
        }
    }
    $this->get(route('guests.index'))->assertDontSee('SECRET-PASSPORT');
})->with(OrganizationRole::cases());

test('unauthorized document changes are refused without flashing sensitive input', function () {
    $t = twoTenants();
    $guest = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'Amina']));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->put(route('guests.update', $guest), ['_property' => $t->a->property->id, 'name' => 'Amina', 'documents' => [['type' => 'passport', 'number' => 'FORGED-SECRET']]])->assertSessionHasErrors('documents');
    expect(json_encode(session()->all()))->not->toContain('FORGED-SECRET');
});

test('staff without identity permission cannot erase documents with an empty list', function () {
    $t = twoTenants();
    $guest = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'Amina']));
    inTenant($t->a->property, fn () => $guest->documents()->create(['type' => 'passport', 'number' => 'KEEP-IDENTITY']));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->put(route('guests.update', $guest), ['_property' => $t->a->property->id, 'name' => 'Amina', 'documents' => []])->assertSessionHasErrors('documents');
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('guests.show', $guest))->assertInertia(fn (Assert $page) => $page->where('documents.0.number', 'KEEP-IDENTITY'));
});
