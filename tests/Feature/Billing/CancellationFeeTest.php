<?php

use App\Domains\Billing\Actions\ProposeFee;
use App\Domains\Billing\Actions\ResolveFee;
use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Actions\ChangeTaxRule;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

/**
 * A 2-night stay arriving 2026-12-10 at 11000 a night (base 10000, one child 1000),
 * on a plan with the given policy.
 *
 * @param  array<string, mixed>  $policy
 * @return array{User, Reservation}
 */
function bookedWithPolicy(array $policy): array
{
    [$owner, , $property] = organizationWithOwner();

    return [$owner, inTenant($property, function () use ($owner, $policy) {
        $details = BookingForm::details($owner);
        RatePlan::query()->whereKey($details['rate_plan_id'])->update($policy);

        return app(CreateReservation::class)->handle($owner, $details);
    })];
}

function cancelOn(User $owner, Reservation $reservation, string $localDate): ?FeeProposal
{
    $property = Property::query()->withoutGlobalScopes()->findOrFail($reservation->property_id);
    $property->update(['business_date' => $localDate]);

    return inTenant($property, function () use ($owner, $reservation) {
        app(CancelReservation::class)->handle($owner, $reservation, 'Guest changed plans');

        return FeeProposal::query()->where('reservation_id', $reservation->id)->first();
    });
}

test('cancelling proposes the fee the policy gives for the property local date', function (array $policy, string $cancelledOn, ?int $fee) {
    [$owner, $reservation] = bookedWithPolicy($policy);

    $proposal = cancelOn($owner, $reservation, $cancelledOn);

    expect($proposal?->amount)->toBe($fee);
    if ($fee !== null) {
        expect($proposal->kind)->toBe('cancellation')->and($proposal->currency->value)->toBe('USD')->and($proposal->status)->toBe('proposed');
    }
})->with([
    'percent, inside the free window' => [['cancellation_free_days' => 7, 'cancellation_charge' => 'percent', 'cancellation_percent' => 25], '2026-12-03', null],
    'percent, outside the free window' => [['cancellation_free_days' => 7, 'cancellation_charge' => 'percent', 'cancellation_percent' => 25], '2026-12-04', 5500],
    'first night, inside the free window' => [['cancellation_free_days' => 2, 'cancellation_charge' => 'first_night'], '2026-12-08', null],
    'first night, outside the free window' => [['cancellation_free_days' => 2, 'cancellation_charge' => 'first_night'], '2026-12-09', 11000],
    'full stay, inside the free window' => [['cancellation_free_days' => 30, 'cancellation_charge' => 'full_stay'], '2026-11-10', null],
    'full stay, outside the free window' => [['cancellation_free_days' => 30, 'cancellation_charge' => 'full_stay'], '2026-11-11', 22000],
    'never free' => [['cancellation_charge' => 'first_night'], '2026-01-01', 11000],
    'no policy' => [[], '2026-12-09', null],
]);

test('only roles with charges.post post or waive a proposed fee over HTTP', function (OrganizationRole $role, bool $allowed, string $decision) {
    $t = twoTenants();
    $this->travelTo(CarbonImmutable::parse('2026-12-09 10:00', $t->a->property->timezone));
    [$reservation, $proposal] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        RatePlan::query()->whereKey($details['rate_plan_id'])->update(['cancellation_charge' => 'first_night']);
        $reservation = app(CancelReservation::class)->handle($t->a->owner, app(CreateReservation::class)->handle($t->a->owner, $details), 'Guest changed plans');

        return [$reservation, FeeProposal::query()->sole()];
    });
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);
    if ($role !== OrganizationRole::Housekeeping) {
        $this->actingAs($actor)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('feeProposals.0.id', $proposal->id)->where('feeProposals.0.kind', 'cancellation')->where('feeProposals.0.amount', 11000)
            ->where('feeProposals.0.status', 'proposed')->where('feeProposals.0.can_resolve', $allowed));
    }

    $response = $this->actingAs($actor)->post(route('fee-proposals.resolve', $proposal), ['_property' => $t->a->property->id, 'decision' => $decision, 'reason' => 'Per policy']);

    $allowed ? $response->assertSessionHasNoErrors()->assertRedirect(route('reservations.show', $reservation)) : $response->assertForbidden();
    expect(inTenant($t->a->property, fn () => $proposal->fresh()->status))->toBe($allowed ? ($decision === 'post' ? 'posted' : 'waived') : 'proposed');
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk, OrganizationRole::Finance], true)], OrganizationRole::cases()))
    ->with(['post', 'waive']);

test('the fee form requires a decision and a reason, and the refusal is audited', function () {
    $t = twoTenants();
    $this->travelTo(CarbonImmutable::parse('2026-12-09 10:00', $t->a->property->timezone));
    $proposal = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        RatePlan::query()->whereKey($details['rate_plan_id'])->update(['cancellation_charge' => 'first_night']);
        app(CancelReservation::class)->handle($t->a->owner, app(CreateReservation::class)->handle($t->a->owner, $details), 'Guest changed plans');

        return FeeProposal::query()->sole();
    });

    $this->actingAs($t->a->owner)->post(route('fee-proposals.resolve', $proposal), ['_property' => $t->a->property->id, 'decision' => 'forgive', 'reason' => ' '])
        ->assertSessionHasErrors(['decision', 'reason']);

    expect(inTenant($t->a->property, fn () => $proposal->fresh()->status))->toBe('proposed')
        ->and(Activity::query()->where('description', 'Sensitive form refused')->where('subject_id', $proposal->id)->exists())->toBeTrue();
});

test('posting a proposed fee charges the folio with the reason, audits it and marks it a fiscal exception candidate at Zanzibar', function () {
    [$owner, $reservation] = bookedWithPolicy(['cancellation_charge' => 'first_night']);
    $proposal = cancelOn($owner, $reservation, '2026-12-09');

    inTenant(Property::query()->withoutGlobalScopes()->findOrFail($reservation->property_id), function () use ($owner, $proposal) {
        $resolved = app(ResolveFee::class)->handle($owner, $proposal, 'post', 'Late cancellation per policy');

        $posting = Posting::query()->findOrFail($resolved->posting_id);
        expect($resolved->status)->toBe('posted')->and($resolved->reason)->toBe('Late cancellation per policy')->and($resolved->fiscal_exception_candidate)->toBeTrue()
            ->and($posting->only(['type', 'category', 'amount', 'folio_id']))->toBe(['type' => 'charge', 'category' => 'cancellation_fee', 'amount' => 11000, 'folio_id' => $proposal->folio_id])
            ->and(Folio::query()->findOrFail($proposal->folio_id)->balance()->amount)->toBe(11000)
            ->and(Activity::query()->where('description', 'Fee posted')->sole()->getExtraProperty('reason'))->toBe('Late cancellation per policy');
        expect(fn () => app(ResolveFee::class)->handle($owner, $resolved, 'waive', 'Changed my mind'))->toThrow(ValidationException::class);
    });
});

test('a fee posted at a mainland property is not a fiscal exception candidate', function () {
    [$owner, $reservation] = bookedWithPolicy(['cancellation_charge' => 'full_stay']);
    $property = Property::query()->withoutGlobalScopes()->findOrFail($reservation->property_id);
    $property->update(['jurisdiction_profile' => JurisdictionProfile::MainlandTanzania, 'zra_hotel_code' => null]);
    $proposal = cancelOn($owner, $reservation, '2026-12-09');

    expect(inTenant($property, fn () => app(ResolveFee::class)->handle($owner, $proposal, 'post', 'Policy')->fiscal_exception_candidate))->toBeFalse();
});

test('waiving a proposed fee posts nothing and audits the reason', function () {
    [$owner, $reservation] = bookedWithPolicy(['cancellation_charge' => 'first_night']);
    $proposal = cancelOn($owner, $reservation, '2026-12-09');

    inTenant(Property::query()->withoutGlobalScopes()->findOrFail($reservation->property_id), function () use ($owner, $proposal) {
        $resolved = app(ResolveFee::class)->handle($owner, $proposal, 'waive', 'Regular guest');

        expect($resolved->status)->toBe('waived')->and($resolved->posting_id)->toBeNull()->and($resolved->fiscal_exception_candidate)->toBeFalse()
            ->and(Posting::query()->count())->toBe(0)
            ->and(Activity::query()->where('description', 'Fee waived')->sole()->getExtraProperty('reason'))->toBe('Regular guest');
    });
});

test('fees freeze applicable tax lines and rule versions when posted', function (string $kind) {
    [$owner, $reservation] = bookedWithPolicy(['cancellation_charge' => 'full_stay', 'no_show_charge' => 'full_stay']);
    $property = Property::query()->withoutGlobalScopes()->findOrFail($reservation->property_id);
    $this->travelTo(CarbonImmutable::parse('2026-12-09 10:00', $property->timezone));
    inTenant($property, function () use ($owner, $reservation, $kind) {
        $terms = ['name' => 'VAT', 'category' => null, 'kind' => 'percentage', 'rate' => 1800, 'amount' => null, 'currency' => null, 'inclusive' => false, 'applies_to' => 'both', 'rounding' => 'half_up', 'effective_from' => '2026-01-01', 'effective_to' => null];
        $rule = app(CreateTaxRule::class)->handle($owner, $terms);
        $proposal = app(ProposeFee::class)->handle($owner, $reservation, $kind);
        $resolved = app(ResolveFee::class)->handle($owner, $proposal, 'post', 'Policy');

        app(ChangeTaxRule::class)->handle($owner, $rule, [...$terms, 'rate' => 2000, 'effective_from' => '2026-12-01']);
        $posting = Posting::query()->findOrFail($resolved->posting_id);
        expect($posting->net)->toBe(22000)->and($posting->amount)->toBe(25960)
            ->and($posting->tax_lines)->toEqual([['name' => 'VAT', 'category' => null, 'kind' => 'percentage', 'inclusive' => false, 'rate' => 1800, 'per_person_night' => null, 'amount' => 3960]])
            ->and($posting->tax_rule_ids)->toEqual([$rule->id]);
    });
})->with(['cancellation', 'no_show']);

test('the no-show fee proposal follows the plan no-show fee', function (array $policy, ?int $fee) {
    [$owner, $reservation] = bookedWithPolicy($policy);
    $property = Property::query()->withoutGlobalScopes()->findOrFail($reservation->property_id);

    $proposal = inTenant($property, fn () => app(ProposeFee::class)->handle($owner, $reservation, 'no_show'));

    expect($proposal?->amount)->toBe($fee);
    if ($fee !== null) {
        expect($proposal->kind)->toBe('no_show')
            ->and(inTenant($property, fn () => app(ProposeFee::class)->handle($owner, $reservation, 'no_show')->id))->toBe($proposal->id);
        $posted = inTenant($property, fn () => app(ResolveFee::class)->handle($owner, $proposal, 'post', 'Guest never arrived'));
        expect($posted->fiscal_exception_candidate)->toBeTrue()->and(inTenant($property, fn () => Posting::query()->sole()->category))->toBe('no_show_fee');
    }
})->with([
    'first night' => [['no_show_charge' => 'first_night', 'cancellation_free_days' => 365, 'cancellation_charge' => 'full_stay'], 11000],
    'percent' => [['no_show_charge' => 'percent', 'no_show_percent' => 50], 11000],
    'full stay' => [['no_show_charge' => 'full_stay'], 22000],
    'none' => [['cancellation_charge' => 'full_stay'], null],
]);
