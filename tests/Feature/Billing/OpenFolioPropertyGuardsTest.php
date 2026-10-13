<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

/** A reservation in $property whose stay has ended, its folio carrying one 500 charge. */
function departedFolioWithCharge(User $owner, Property $property): Folio
{
    return inTenant($property, function () use ($owner) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $reservation->update(['status' => 'checked_out']);
        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        app(PostExtraCharge::class)->handle($owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 500]);

        return $folio;
    });
}

/** ponytail: payments land in #73; until then a raw negative posting settles the folio. */
function settleFolio(Property $property, Folio $folio): void
{
    inTenant($property, fn () => Posting::query()->create([
        'folio_id' => $folio->id, 'type' => 'payment', 'category' => 'cash', 'quantity' => 1, 'unit_price' => 0, 'net' => 0,
        'amount' => -$folio->fresh()->balance()->amount, 'currency' => $folio->currency, 'tax_lines' => [], 'tax_rule_ids' => [],
        'idempotency_key' => 'settle', 'business_date' => now()->toDateString(), 'posted_at' => now(),
    ]));
}

test('a folio with an open balance refuses archival with a clear audited reason, and settling it allows archival', function () {
    $this->travelTo(now()->setDate(2026, 12, 9));
    $t = twoTenants();
    $folio = departedFolioWithCharge($t->a->owner, $t->a->otherProperty);

    $this->actingAs($t->a->owner)
        ->from(route('properties.index'))
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'))
        ->assertSessionHasErrors(['archive' => 'This property has folios with open balances. Settle them before archiving the property.']);
    expect(inTenant($t->a->organization, fn () => $t->a->otherProperty->fresh()->archived_at))->toBeNull();
    $refusal = Activity::query()->where('subject_id', $t->a->otherProperty->id)->where('event', 'archive_refused')->sole();
    expect($refusal->getExtraProperty('reason'))->toBe('This property has folios with open balances. Settle them before archiving the property.');

    settleFolio($t->a->otherProperty, $folio);

    $this->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    expect(inTenant($t->a->organization, fn () => $t->a->otherProperty->fresh()->archived_at))->not->toBeNull();
});
