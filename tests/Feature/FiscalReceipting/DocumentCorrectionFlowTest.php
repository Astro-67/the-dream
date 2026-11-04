<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

test('the issued document opens correction with its own number and only verified replacements', function (string $type) {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant, $type);

    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.document.number", 'OLD-232')
            ->where("documentCorrections.{$old->id}.document.type", $type)
            ->where("documentCorrections.{$old->id}.submitUrl", route('issued-documents.corrections.store', $old))
            ->where("documentCorrections.{$old->id}.replacements.0.number", 'NEW-232')
            ->where("documentCorrections.{$old->id}.replacements.0.id", $new->id)
            ->where("documentCorrections.{$old->id}.business.name", 'Accepted business')
            ->where("documentCorrections.{$old->id}.business.email", 'desk@example.test'))
        ->assertDontSee('PRIVATE-GUEST-ID')
        ->assertDontSee('P1234567');
    Http::assertNothingSent();
})->with(['services_receipt', 'checkout_report']);

test('non managers can read documents but cannot receive correction controls', function () {
    $tenant = twoTenants()->a;
    [$stay, $old] = correctionDocuments($tenant);

    $this->actingAs($tenant->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.submitUrl", null)
            ->where("documentCorrections.{$old->id}.replacements", []));
});
