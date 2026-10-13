<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
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
];
