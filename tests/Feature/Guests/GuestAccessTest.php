<?php

use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;

test('contacts in another property of the same organization are invisible', function (string $domain, string $model) {
    $t = twoTenants();
    $record = inTenant($t->a->otherProperty, fn () => $model::query()->create(['name' => 'Other property secret']));
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $this->get(route("$domain.index"))->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
    $this->get(route("$domain.show", $record))->assertNotFound();
    $this->get(route("$domain.edit", $record))->assertNotFound();
    $this->put(route("$domain.update", $record), ['_property' => $t->a->property->id, 'name' => 'Hijacked'])->assertNotFound();
    expect(inTenant($t->a->otherProperty, fn () => $record->fresh()->name))->toBe('Other property secret');
})->with([['guests', Guest::class], ['bookers', Booker::class]]);

test('every role has the expected contact access', function (OrganizationRole $role, string $domain, string $model) {
    $t = twoTenants();
    $record = inTenant($t->a->property, fn () => $model::query()->create(['name' => 'Original']));
    $this->actingAs($t->a->member($role))->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $canManage = in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk], true);
    $this->get(route("$domain.index"))->assertStatus($role === OrganizationRole::Housekeeping ? 403 : 200);
    $this->get(route("$domain.create"))->assertStatus($canManage ? 200 : 403);
    $this->get(route("$domain.edit", $record))->assertStatus($canManage ? 200 : 403);
    $this->put(route("$domain.update", $record), ['_property' => $t->a->property->id, 'name' => 'Changed'])->assertStatus($canManage ? 302 : 403);
    $this->post(route("$domain.store"), ['_property' => $t->a->property->id, 'name' => 'New'])->assertStatus($canManage ? 302 : 403);
    expect(inTenant($t->a->property, fn () => $record->fresh()->name))->toBe($canManage ? 'Changed' : 'Original');
})->with(OrganizationRole::cases())->with([['guests', Guest::class], ['bookers', Booker::class]]);

test('contact lists paginate at fifty and retain query parameters', function (string $domain, string $model) {
    $t = twoTenants();
    inTenant($t->a->property, function () use ($model) {
        for ($i = 0; $i < 51; $i++) {
            $model::query()->create(['name' => sprintf('Guest %02d', $i)]);
        }
    });
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->get(route("$domain.index", ['page' => 2, 'filter' => 'all']))->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.total', 51)->where('records.data.0.name', 'Guest 50')->where('records.current_page', 2)->where('records.prev_page_url', fn ($url) => str_contains($url, 'filter=all')));
})->with([['guests', Guest::class], ['bookers', Booker::class]]);

test('invalid documents do not save or flash identity fields', function () {
    $t = twoTenants();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->post(route('guests.store'), ['_property' => $t->a->property->id, 'name' => 'Invalid', 'documents' => [['type' => 'passport', 'number' => 'INVALID-SECRET', 'expiry' => 'not-a-date']]])->assertSessionHasErrors('documents.0.expiry');
    expect(json_encode(session()->all()))->not->toContain('INVALID-SECRET');
    $this->get(route('guests.index'))->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
});

test('editing ordinary guest fields preserves documents while an explicit empty list removes them', function () {
    $t = twoTenants();
    $guest = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'Original']));
    inTenant($t->a->property, fn () => $guest->documents()->create(['type' => 'passport', 'number' => 'KEEP-SECRET']));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->put(route('guests.update', $guest), ['_property' => $t->a->property->id, 'name' => 'Changed'])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->get(route('guests.edit', $guest))->assertInertia(fn (Assert $page) => $page->where('documents.0.number', 'KEEP-SECRET'));
    $this->put(route('guests.update', $guest), ['_property' => $t->a->property->id, 'name' => 'Changed', 'documents' => []])->assertSessionHasNoErrors();
    $this->get(route('guests.show', $guest))->assertInertia(fn (Assert $page) => $page->has('documents', 0));
});
