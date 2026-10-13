<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\TransferPosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TwoTenants;

/** @return array{Reservation, Folio, Posting} */
function folioWithCharge(TwoTenants $t): array
{
    return inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $folio = Folio::query()->sole();

        return [$reservation, $folio, app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 400])];
    });
}

function actorFor(TwoTenants $t, OrganizationRole $role)
{
    return $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);
}

test('only roles with finance.correct reverse a posting', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    [$reservation, , $charge] = folioWithCharge($t);

    $response = $this->actingAs(actorFor($t, $role))->post(route('postings.reverse', $charge), ['_property' => $t->a->property->id, 'reason' => 'Posted to the wrong guest']);

    $allowed ? $response->assertRedirect(route('reservations.show', $reservation)) : $response->assertForbidden();
    expect(inTenant($t->a->property, fn () => Posting::query()->where('type', 'reversal')->count()))->toBe($allowed ? 1 : 0);
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::Finance], true)], OrganizationRole::cases()));

test('a reversal form needs a reason, and a second reversal is refused', function () {
    $t = twoTenants();
    [, , $charge] = folioWithCharge($t);
    $this->actingAs($t->a->member(OrganizationRole::Finance));

    $this->postJson(route('postings.reverse', $charge), ['_property' => $t->a->property->id])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->post(route('postings.reverse', $charge), ['_property' => $t->a->property->id, 'reason' => 'Wrong guest'])->assertSessionHasNoErrors();
    $this->post(route('postings.reverse', $charge), ['_property' => $t->a->property->id, 'reason' => 'Again'])->assertSessionHasErrors('posting');

    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(2);
});

test('only roles with charges.post open a folio or transfer a charge', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    [$reservation, $folio, $charge] = folioWithCharge($t);
    $second = inTenant($t->a->property, fn () => app(OpenFolio::class)->handle($t->a->owner, $reservation, 'USD'));
    $this->actingAs(actorFor($t, $role));

    $open = $this->post(route('reservations.folios.store', $reservation), ['_property' => $t->a->property->id, 'currency' => 'TZS']);
    $move = $this->post(route('postings.transfer', $charge), ['_property' => $t->a->property->id, 'folio_id' => $second->id, 'reason' => 'Company pays']);

    foreach ([$open, $move] as $response) {
        $allowed ? $response->assertRedirect(route('reservations.show', $reservation)) : $response->assertForbidden();
    }
    expect(inTenant($t->a->property, fn () => [Folio::query()->count(), Folio::query()->findOrFail($folio->id)->balance()->amount]))->toBe($allowed ? [3, 0] : [2, 400]);
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::FrontDesk, OrganizationRole::Finance], true)], OrganizationRole::cases()));

test('opening a folio refuses a currency the jurisdiction does not allow, and a transfer refuses another currency', function () {
    $t = twoTenants();
    [$reservation, , $charge] = folioWithCharge($t);
    $tzs = inTenant($t->a->property, fn () => app(OpenFolio::class)->handle($t->a->owner, $reservation, 'TZS'));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->post(route('reservations.folios.store', $reservation), ['_property' => $t->a->property->id, 'currency' => 'GBP'])->assertSessionHasErrors('currency');
    $this->post(route('postings.transfer', $charge), ['_property' => $t->a->property->id, 'folio_id' => $tzs->id, 'reason' => 'Move'])->assertSessionHasErrors('folio_id');

    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(1);
});

test('the folio view shows reversal and transfer links and the correction abilities', function () {
    $t = twoTenants();
    [$reservation, $folio, $charge] = folioWithCharge($t);
    [$second, $in] = inTenant($t->a->property, function () use ($t, $reservation, $folio) {
        $second = app(OpenFolio::class)->handle($t->a->owner, $reservation, 'USD');
        $moved = app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 900]);

        return [$second, app(TransferPosting::class)->handle($t->a->owner, $moved, $second, 'Company pays')];
    });
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('postings.reverse', $charge), ['_property' => $t->a->property->id, 'reason' => 'Wrong guest']);

    $this->actingAs($t->a->member(OrganizationRole::Finance))->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('folios.0.id', $folio->id)->has('folios.0.postings', 4)
        ->where('folios.0.postings.0.id', $charge->id)->where('folios.0.postings.0.cancelled_by', fn ($link) => $link['type'] === 'reversal')
        ->where('folios.0.postings.2.type', 'transfer_out')->where('folios.0.postings.2.related', fn ($link) => $link['category'] === 'laundry')
        ->where('folios.0.postings.3.type', 'reversal')->where('folios.0.postings.3.reason', 'Wrong guest')->where('folios.0.postings.3.related.id', $charge->id)
        ->where('folios.1.id', $second->id)->where('folios.1.postings.0.id', $in->id)->where('folios.1.postings.0.related.folio_id', $folio->id)
        ->where('canReverse', true)->where('canTransfer', true)->where('canOpenFolio', true)
        ->has('folioCurrencies', 3));
    $this->actingAs($t->a->member(OrganizationRole::Auditor))->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canReverse', false)->where('canTransfer', false)->where('canOpenFolio', false));
});
