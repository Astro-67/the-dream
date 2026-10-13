<?php

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'folios.charges.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $folio = inTenant($t->b->property, function () use ($t) {
            app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));

            return Folio::query()->sole();
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'category' => 'minibar', 'quantity' => 1, 'unit_price' => 100, 'idempotency_key' => 'attack'])->assertNotFound();
        }
        expect(inTenant($t->b->property, fn () => Posting::query()->count()))->toBe(0);

        return $responses;
    }),
    'fee-proposals.resolve' => RouteCoverage::attempt(function (TwoTenants $t) {
        $proposal = inTenant($t->b->property, function () use ($t) {
            $details = BookingForm::details($t->b->owner);
            RatePlan::query()->whereKey($details['rate_plan_id'])->update(['cancellation_charge' => 'full_stay']);
            app(CancelReservation::class)->handle($t->b->owner, app(CreateReservation::class)->handle($t->b->owner, $details), 'Bravo cancelled');

            return FeeProposal::query()->sole();
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('fee-proposals.resolve', $proposal), ['_property' => $t->a->property->id, 'decision' => 'post', 'reason' => 'attack'])->assertNotFound();
        }
        $responses[] = $this->actingAs($t->a->owner)->post(route('fee-proposals.resolve', $proposal), ['_property' => $t->b->property->id, 'decision' => 'waive', 'reason' => 'attack'])->assertForbidden();
        inTenant($t->b->property, function () use ($proposal) {
            expect($proposal->fresh()->status)->toBe('proposed')->and(Posting::query()->count())->toBe(0);
        });

        return $responses;
    }),
];
