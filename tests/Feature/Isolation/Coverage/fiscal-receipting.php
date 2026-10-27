<?php

use App\Domains\FiscalReceipting\Actions\SaveVfmsCredentials;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/** Bravo saves its own credentials first, so an attack has something to overwrite. */
$bravoCredentials = function (TwoTenants $t): void {
    inTenant($t->b->property, fn () => app(SaveVfmsCredentials::class)->handle($t->b->owner, $t->b->property, 'BRAVO-INT', 'BRAVO-REG', 'BRAVO-TOKEN'));
};

$recoverForeignFiling = function (TwoTenants $t, string $route) {
    [$reservation, $message] = inTenant($t->b->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
        $message = OutboxMessage::query()->create([
            'integration' => 'vfms', 'subject_type' => $reservation->getMorphClass(), 'subject_id' => $reservation->id,
            'sequence' => 1, 'stream_key' => hash('sha256', $reservation->id), 'idempotency_key' => 'isolation:'.$reservation->id,
            'event' => 'accommodation', 'payload' => ['reservation_id' => $reservation->id], 'status' => 'rejected', 'attempt_count' => 1,
        ]);

        return [$reservation, $message];
    });
    $responses = [];
    foreach ($t->a->members() as $member) {
        $responses[] = $this->actingAs($member)->post(route($route, [$reservation, $message]), [
            '_property' => $t->a->property->id, 'reason' => 'Foreign filing attack',
        ])->assertNotFound();
    }
    inTenant($t->b->property, function () use ($message) {
        expect($message->fresh()->status)->toBe('rejected')->and($message->revisions()->count())->toBe(0);
    });

    return $responses;
};

return [
    'reservations.fiscal-report.reveal-identity' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.fiscal-report.reveal-identity', $reservation), [
                '_property' => $t->a->property->id,
            ])->assertNotFound();
        }
        expect(DB::table('activity_log')->where('subject_id', $reservation->id)->where('event', 'fiscal_identity_revealed')->exists())->toBeFalse();

        return $responses;
    }),
    'reservations.vfms.close-without-filing' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, function () use ($t) {
            $stay = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
            $stay->update(['status' => 'checked_out']);

            return $stay;
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.vfms.close-without-filing', $reservation), [
                '_property' => $t->a->property->id, 'reason' => 'Foreign stay attack',
            ])->assertNotFound();
        }
        expect(inTenant($t->b->property, fn () => $reservation->fresh()->getAttribute('fiscal_exception_reason')))->toBeNull();

        return $responses;
    }),
    'reservations.vfms.refile' => RouteCoverage::attempt(fn (TwoTenants $t) => $recoverForeignFiling->call($this, $t, 'reservations.vfms.refile')),
    'reservations.vfms.retry' => RouteCoverage::attempt(fn (TwoTenants $t) => $recoverForeignFiling->call($this, $t, 'reservations.vfms.retry')),
    'properties.vfms.update' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoCredentials) {
        $bravoCredentials($t);
        $form = ['integration_id' => 'ALPHA-INT', 'registration_id' => 'ALPHA-REG', 'token' => 'ALPHA-TOKEN'];
        $responses = [
            $this->actingAs($t->a->owner)->put(route('properties.vfms.update', $t->b->property->id), [...$form, '_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('properties.vfms.update', $t->b->property->id), [...$form, '_property' => $t->b->property->id])->assertForbidden(),
        ];
        $saved = VfmsCredential::for($t->b->property);
        expect([$saved->integration_id, $saved->token])->toBe(['BRAVO-INT', 'BRAVO-TOKEN']);

        return $responses;
    }),
    'properties.vfms.mode' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoCredentials) {
        $bravoCredentials($t);
        $form = ['mode' => 'live', 'confirmation' => 'LIVE', 'reason' => 'Attack'];
        $responses = [
            $this->actingAs($t->a->owner)->put(route('properties.vfms.mode', $t->b->property->id), [...$form, '_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->put(route('properties.vfms.mode', $t->b->property->id), [...$form, '_property' => $t->b->property->id])->assertForbidden(),
        ];
        expect(VfmsCredential::for($t->b->property)->mode)->toBe(VfmsMode::Sandbox);

        return $responses;
    }),
    'reservations.fiscal-report' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('reservations.fiscal-report', $reservation->reservation_number))->assertNotFound();
        }

        return $responses;
    }),
];
