<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use Inertia\Testing\AssertableInertia;
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

    'reservations.folios.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.folios.store', $reservation), ['_property' => $t->a->property->id, 'currency' => 'TZS'])->assertNotFound();
        }
        expect(inTenant($t->b->property, fn () => Folio::query()->count()))->toBe(1);

        return $responses;
    }),
    'postings.reverse' => RouteCoverage::attempt(function (TwoTenants $t) {
        $posting = inTenant($t->b->property, function () use ($t) {
            app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));

            return app(PostExtraCharge::class)->handle($t->b->owner, Folio::query()->sole(), ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 100]);
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('postings.reverse', $posting), ['_property' => $t->a->property->id, 'reason' => 'attack'])->assertNotFound();
        }
        expect(inTenant($t->b->property, fn () => Posting::query()->count()))->toBe(1);

        return $responses;
    }),
    'postings.transfer' => RouteCoverage::attempt(function (TwoTenants $t) {
        [$posting, $target] = inTenant($t->b->property, function () use ($t) {
            $reservation = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
            $posting = app(PostExtraCharge::class)->handle($t->b->owner, Folio::query()->sole(), ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 100]);

            return [$posting, app(OpenFolio::class)->handle($t->b->owner, $reservation, 'USD')];
        });
        $own = inTenant($t->a->property, function () use ($t) {
            $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
            $posting = app(PostExtraCharge::class)->handle($t->a->owner, Folio::query()->sole(), ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 100]);
            app(OpenFolio::class)->handle($t->a->owner, $reservation, 'USD');

            return $posting;
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('postings.transfer', $posting), ['_property' => $t->a->property->id, 'folio_id' => $target->id, 'reason' => 'attack'])->assertNotFound();
        }
        // Moving Alpha's own charge into Bravo's folio is refused like a wrong folio.
        $responses[] = $this->actingAs($t->a->owner)->post(route('postings.transfer', $own), ['_property' => $t->a->property->id, 'folio_id' => $target->id, 'reason' => 'attack'])->assertSessionHasErrors('folio_id');
        expect(inTenant($t->b->property, fn () => Posting::query()->count()))->toBe(1)
            ->and(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(1);

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
    'folios.payments.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $folio = inTenant($t->b->property, function () use ($t) {
            app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));

            return Folio::query()->sole();
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 100, 'idempotency_key' => 'attack'])->assertNotFound();
        }
        expect(inTenant($t->b->property, fn () => [Posting::query()->count(), Receipt::query()->count()]))->toBe([0, 0]);

        return $responses;
    }),
    'receipts.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        $receipt = inTenant($t->b->property, function () use ($t) {
            app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));

            return app(RecordPayment::class)->handle($t->b->owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => 100, 'currency' => 'USD'])->receipt;
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('receipts.show', $receipt))->assertNotFound();
        }

        return $responses;
    }),
    'cashier-shifts.index' => RouteCoverage::attempt(function (TwoTenants $t) {
        inTenant($t->b->property, fn () => app(OpenCashierShift::class)->handle($t->b->owner, ['USD' => 4242]));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $response = $this->actingAs($member)->get(route('cashier-shifts.index'));
            if ($response->status() === 200) {
                $response->assertInertia(fn (AssertableInertia $page) => $page->where('openShift', null)->where('shifts', fn ($shifts) => $shifts === null || count($shifts['data']) === 0)->etc());
            } else {
                $response->assertForbidden();
            }
            $responses[] = $response;
        }

        return $responses;
    }),
    'cashier-shifts.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $response = $this->actingAs($t->a->owner)->post(route('cashier-shifts.store'), ['_property' => $t->b->property->id, 'floats' => ['USD' => 100]])->assertForbidden();
        expect(inTenant($t->b->property, fn () => CashierShift::query()->count()))->toBe(0);

        return $response;
    }),
    'cashier-shifts.close' => RouteCoverage::attempt(function (TwoTenants $t) {
        $shift = inTenant($t->b->property, fn () => app(OpenCashierShift::class)->handle($t->b->owner, ['USD' => 100]));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('cashier-shifts.close', $shift), ['_property' => $t->a->property->id, 'counted' => ['USD' => 0], 'variance_reason' => 'attack'])->assertNotFound();
        }
        expect(inTenant($t->b->property, fn () => $shift->fresh()->status))->toBe('open');

        return $responses;
    }),
];
