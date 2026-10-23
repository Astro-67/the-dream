<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

/** A confirmed USD stay of two nights at USD 100 with no tax rules, on its arrival day. */
function dueStay(): array
{
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10']);
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, [...BookingForm::details($t->a->owner), 'adults' => 1, 'children' => 0]));

    return [$t, $reservation];
}

function duePay(object $t, int $amount): void
{
    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => $amount, 'currency' => 'USD']));
}

test('an unpaid confirmed stay is due its unposted nights', function () {
    [$t, $reservation] = dueStay();

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.estimate', ['nights' => 2, 'amount' => 20000])->where('totals.0.due', 20000)->where('totals.0.status', 'unpaid'));
});
