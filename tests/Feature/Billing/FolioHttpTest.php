<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('front desk charges a free-text item at its VAT-inclusive price and sees it beside existing extras', function () {
    $t = twoTenants();
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    [$reservation, $folio] = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $folio = Folio::query()->sole();
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 100]);
        app(CreateTaxRule::class)->handle($t->a->owner, [
            'name' => 'VAT', 'kind' => 'percentage', 'rate' => 1800, 'amount' => null, 'currency' => null,
            'inclusive' => false, 'applies_to' => 'extras', 'rounding' => 'half_up',
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        return [$reservation, $folio];
    });

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->post(route('folios.charges.store', $folio), [
            '_property' => $t->a->property->id, 'description' => 'Sunset boat trip', 'quantity' => 2,
            'unit_price' => 590, 'idempotency_key' => 'boat-trip',
        ])->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();

    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('folios.data.0.balance', 1280)
        ->where('folios.data.0.postings.data.0.category', 'minibar')
        ->where('folios.data.0.postings.data.1.description', 'Sunset boat trip')
        ->where('folios.data.0.postings.data.1.amount', 1180)
        ->where('folios.data.0.postings.data.1.net', 1000));
});

test('only roles with charges.post post an extra charge', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($actor)->post(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'category' => 'minibar', 'description' => 'Water', 'quantity' => 2, 'unit_price' => 150, 'idempotency_key' => 'k-1']);

    $allowed ? $response->assertRedirect(route('reservations.show', $reservation)) : $response->assertForbidden();
    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe($allowed ? 1 : 0);
    if ($role !== OrganizationRole::Housekeeping) {
        $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
            ->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canPostCharge', $allowed));
    }
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::FrontDesk, OrganizationRole::Finance], true)], OrganizationRole::cases()));

test('a resubmitted charge form posts once', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $form = ['_property' => $t->a->property->id, 'description' => 'Laundry', 'quantity' => 1, 'unit_price' => 900, 'idempotency_key' => '01JABCDEFGHJKMNPQRSTVWXYZ0'];
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->post(route('folios.charges.store', $folio), $form)->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $folio), $form)->assertSessionHasNoErrors();

    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(1);
});

test('the charge form validates item name, whole-number quantity, price and key', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());

    $this->actingAs($t->a->owner)->postJson(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'category' => 'spa', 'quantity' => 0, 'unit_price' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors(['description', 'quantity', 'unit_price', 'idempotency_key']);
    $this->postJson(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'description' => str_repeat('a', 256), 'quantity' => 1.5, 'unit_price' => 100, 'idempotency_key' => 'fraction'])
        ->assertUnprocessable()->assertJsonValidationErrors(['description', 'quantity']);
});

test('the reservation shows its folio postings, totals per currency and balance', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->post(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'description' => 'Minibar', 'quantity' => 2, 'unit_price' => 150, 'idempotency_key' => 'a']);
    $this->post(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'description' => 'Laundry', 'quantity' => 1, 'unit_price' => 1000, 'idempotency_key' => 'b']);

    $this->actingAs($t->a->member(OrganizationRole::Auditor))->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('folios.data', 1)
        ->where('folios.data.0.id', $folio->id)->where('folios.data.0.currency', 'USD')
        ->where('folios.data.0.balance', 1300)
        ->has('folios.data.0.postings.data', 2)
        ->where('folios.data.0.postings.data.0.description', 'Minibar')->where('folios.data.0.postings.data.0.amount', 300)
        ->where('totals.0.currency', 'USD')->where('totals.0.charges', 1300)->where('totals.0.extras', 1300)->where('totals.0.balance', 1300));
});

test('posting pages stay bounded while totals and reversal links span all pages', function () {
    $t = twoTenants();
    [$reservation, $folio, $charge] = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $folio = Folio::query()->sole();
        $charge = null;
        for ($index = 0; $index < 51; $index++) {
            $posting = app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 10]);
            $charge ??= $posting;
        }
        app(ReversePosting::class)->handle($t->a->owner, $charge, 'Wrong charge');

        return [$reservation, $folio, $charge];
    });
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->has('folios.data.0.postings.data', 50)->where('folios.data.0.postings.total', 52)
        ->where('folios.data.0.balance', 500)->where('totals.0.balance', 500)->where('totals.0.charges', 500)
        ->where('folios.data.0.postings.data.0.cancelled_by.type', 'reversal'));
    $this->get(route('reservations.show', [$reservation, 'postings_page_'.$folio->id => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('folios.data.0.postings.data', 2)->where('folios.data.0.postings.current_page', 2)
        ->where('folios.data.0.postings.data.1.related.id', $charge->id)->where('totals.0.balance', 500));
});

test('folio pages stay bounded and currency totals include folios outside the page', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        for ($index = 0; $index < 50; $index++) {
            $folio = app(OpenFolio::class)->handle($t->a->owner, $reservation, 'USD');
        }
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 100]);

        return $reservation;
    });
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->has('folios.data', 50)->where('folios.total', 51)->where('totals.0.balance', 100));
    $this->get(route('reservations.show', [$reservation, 'folios_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('folios.data', 1)->where('folios.current_page', 2)->where('folios.data.0.balance', 100)->where('totals.0.balance', 100));
});
