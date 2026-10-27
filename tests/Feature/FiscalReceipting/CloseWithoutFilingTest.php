<?php

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxSweeper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

/** Recorded failures are deliberately synthetic: this action must never contact ZRA. */
function closeUnfiledStay(TenantFixture $tenant, bool $sandboxDelivered = false): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $sandboxDelivered) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        $reservation->update(['status' => ReservationStatus::CheckedOut]);
        foreach (['accommodation', 'checkout'] as $index => $event) {
            $message = OutboxMessage::query()->create([
                'integration' => 'vfms', 'subject_type' => $reservation->getMorphClass(), 'subject_id' => $reservation->id,
                'sequence' => $index + 1, 'stream_key' => hash('sha256', $reservation->id), 'idempotency_key' => 'close:'.$reservation->id.':'.$index,
                'event' => $event, 'payload' => ['reservation_id' => $reservation->id, 'mode' => 'live'],
                'status' => $index === 0 ? ($sandboxDelivered ? 'delivered' : 'rejected') : ($sandboxDelivered ? 'rejected' : 'blocked'),
                'attempt_count' => $index === 0 || $sandboxDelivered ? 1 : 0,
            ]);
            if ($index === 0 && $sandboxDelivered) {
                $message->forceFill(['provider_references' => ['mode' => 'sandbox', 'responseNumber' => 'SBX-OLD']])->save();
            }
        }

        return $reservation;
    });
}

// protects=departed unfiled stays are resolved atomically without fabricating ZRA acceptance (#176); fails_when=messages remain blocked or evidence/exception/audit is lost; no_claim=live cleanup.
test('close without filing supersedes rejected and blocked calls and records a fiscal exception', function (bool $sandboxDelivered) {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant, $sandboxDelivered);
    $before = inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get()->map(fn (OutboxMessage $message) => ['payload' => $message->payload, 'references' => $message->getAttribute('provider_references')])->all());
    $this->actingAs($tenant->owner)->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'Departed before VFMS filing worked',
    ])->assertRedirect()->assertSessionHasNoErrors();
    inTenant($tenant->property, function () use ($reservation, $sandboxDelivered, $before) {
        expect(OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->pluck('status')->all())
            ->toBe($sandboxDelivered ? ['delivered', 'superseded'] : ['superseded', 'superseded'])
            ->and($reservation->fresh()->getAttribute('fiscal_exception_reason'))->toBe('Departed before VFMS filing worked');
        foreach (OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get() as $index => $message) {
            expect($message->payload)->toEqual($before[$index]['payload'])
                ->and($message->getAttribute('provider_references'))->toEqual($before[$index]['references']);
        }
    });
    expect(DB::table('activity_log')->where('event', 'closed_without_filing')->where('subject_id', $reservation->id)
        ->where('causer_id', $tenant->owner->id)->where('properties->reason', 'Departed before VFMS filing worked')->exists())->toBeTrue();
    $this->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
        ->where('fiscalExceptions.0.reservationNumber', $reservation->reservation_number)
        ->where('fiscalExceptions.0.reason', 'Departed before VFMS filing worked'));
})->with([false, true]);

// protects=closing cannot hide a Live/uncertain filing or an in-house stay (#176); fails_when=an unsafe stream is superseded or partially resolved; no_claim=provider reconciliation.
test('close without filing refuses unsafe stays without changing any message', function (string $case) {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant);
    inTenant($tenant->property, function () use ($reservation, $case) {
        $message = OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->firstOrFail();
        match ($case) {
            'in_house' => $reservation->update(['status' => ReservationStatus::CheckedIn]),
            'live' => $message->forceFill(['status' => 'delivered', 'provider_references' => ['mode' => 'live']])->save(),
            'missing_mode' => $message->forceFill(['status' => 'delivered'])->save(),
            'unknown', 'exhausted_unknown', 'sending', 'pending' => $message->forceFill(['status' => $case])->save(),
            'claim' => $message->forceFill(['claim_token' => 'active'])->save(),
            'sandbox_active' => $message->forceFill(['status' => 'delivered', 'provider_references' => ['mode' => 'sandbox'], 'claim_token' => 'active'])->save(),
            'attempt' => $message->attempts()->create(['organization_id' => $message->organization_id, 'property_id' => $message->property_id, 'attempt_number' => 1, 'outcome' => 'sending', 'request_body' => '{}']),
        };
    });
    $before = inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->pluck('status', 'id')->all());
    $this->actingAs($tenant->owner)->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'Departed before VFMS filing worked',
    ])->assertSessionHasErrors('vfms');
    expect(inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->pluck('status', 'id')->all()))->toBe($before)
        ->and(inTenant($tenant->property, fn () => $reservation->fresh()->getAttribute('fiscal_exception_reason')))->toBeNull()
        ->and(DB::table('activity_log')->where('event', 'refused')->where('subject_id', $reservation->id)->exists())->toBeTrue();
})->with(['in_house', 'live', 'missing_mode', 'unknown', 'exhausted_unknown', 'sending', 'pending', 'claim', 'sandbox_active', 'attempt']);

// protects=only OutboxRecover roles can close and a reason remains mandatory (#176); fails_when=finance closes or a blank form changes the stay; no_claim=custom permission grants.
test('close without filing follows recovery roles and requires a reason', function (OrganizationRole $role) {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant);
    $actor = $role === OrganizationRole::Owner ? $tenant->owner : $tenant->member($role);
    $allowed = in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true);
    $response = $this->actingAs($actor)->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'Departed before VFMS filing worked',
    ]);
    if ($allowed) {
        $response->assertRedirect()->assertSessionHasNoErrors();
    } else {
        $response->assertForbidden();
        expect(DB::table('activity_log')->where('event', 'refused')->where('causer_id', $actor->id)->exists())->toBeTrue();
    }
    expect(inTenant($tenant->property, fn () => $reservation->fresh()->getAttribute('fiscal_exception_reason')))->toBe($allowed ? 'Departed before VFMS filing worked' : null);
})->with(OrganizationRole::cases());

// protects=reason validation and duplicate submissions do not overwrite evidence (#176); fails_when=blank reason closes or second close replaces reason; no_claim=browser double-click timing.
test('close without filing validates reason and refuses duplicate closure', function () {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant);
    foreach (['', str_repeat('x', 501)] as $reason) {
        $this->actingAs($tenant->owner)->post(route('reservations.vfms.close-without-filing', $reservation), [
            '_property' => $tenant->property->id, 'reason' => $reason,
        ])->assertSessionHasErrors('reason');
    }
    $this->actingAs($tenant->owner)->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'Departed before VFMS filing worked',
    ])->assertSessionHasNoErrors();
    $this->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'A replacement reason',
    ])->assertSessionHasErrors('vfms');
    expect(inTenant($tenant->property, fn () => $reservation->fresh()->getAttribute('fiscal_exception_reason')))->toBe('Departed before VFMS filing worked');
});

// protects=the operator can run the same audited recovery without a browser (#176); fails_when=CLI ignores property/actor/reason or cannot close the stay; no_claim=server deployment.
test('the close without filing command scopes the reservation number to its requested property', function () {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant, true);
    $options = ['reservation' => $reservation->reservation_number, '--actor' => $tenant->owner->id, '--property' => $tenant->property->id, '--reason' => 'Departed before VFMS filing worked'];
    $this->artisan('outbox:close-without-filing', [...$options, '--property' => $tenant->otherProperty->id])->assertFailed();
    $this->artisan('outbox:close-without-filing', [...$options, '--reason' => ''])->assertFailed();
    $this->artisan('outbox:close-without-filing', [...$options, '--actor' => $tenant->member(OrganizationRole::Finance)->id])->assertFailed();
    expect(DB::table('activity_log')->where('event', 'refused')->where('subject_id', $reservation->id)->exists())->toBeTrue();
    $this->artisan('outbox:close-without-filing', $options)->expectsOutput('Closed '.$reservation->reservation_number.' without filing; fiscal exception recorded.')->assertSuccessful();
    expect(inTenant($tenant->property, fn () => $reservation->fresh()->getAttribute('fiscal_exception_reason')))->toBe('Departed before VFMS filing worked');
});

// protects=departed stays expose closure and never label resolved calls as waiting (#176); fails_when=the action is absent or still offered after closure; no_claim=browser rendering.
test('the departed stay offers close without filing then shows its fiscal exception', function () {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant, true);
    $this->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('vfmsCloseWithoutFilingUrl', route('reservations.vfms.close-without-filing', $reservation)));
    $this->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'Departed before VFMS filing worked',
    ])->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('vfmsCloseWithoutFilingUrl', null)
        ->where('vfmsFiscalExceptionReason', 'Departed before VFMS filing worked')
        ->where('vfmsFilings.0.status', 'sandbox')
        ->where('vfmsFilings.1.status', 'closed'));
});

// protects=the existing fee candidates and closed stays share the accountant list and permission (#176); fails_when=fees disappear, other properties leak, or report-only roles see exceptions; no_claim=tax-return preparation.
test('fiscal exceptions lists posted fee candidates and closed stays only for exception reviewers', function (OrganizationRole $role, bool $allowed) {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant);
    inTenant($tenant->property, function () use ($reservation) {
        $reservation->setAttribute('fiscal_exception_reason', 'Departed before VFMS filing worked')->save();
        foreach ([['cancellation', true, 'posted'], ['no_show', false, 'waived']] as [$kind, $candidate, $status]) {
            FeeProposal::query()->create([
                'reservation_id' => $reservation->id, 'folio_id' => Folio::query()->sole()->id,
                'kind' => $kind, 'amount' => 2500, 'currency' => 'USD', 'status' => $status,
                'reason' => 'Late cancellation', 'fiscal_exception_candidate' => $candidate,
            ]);
        }
    });
    $other = inTenant($tenant->otherProperty, function () use ($tenant) {
        $stay = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        $stay->setAttribute('fiscal_exception_reason', 'Other property exception')->save();

        return $stay;
    });
    $actor = $role === OrganizationRole::Owner ? $tenant->owner : $tenant->member($role);
    $this->actingAs($actor)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reports.index'))->assertDontSee('Other property exception')->assertInertia(function (Assert $page) use ($allowed, $reservation) {
            if (! $allowed) {
                return $page->where('fiscalExceptions', null);
            }

            return $page->has('fiscalExceptions', 2)
                ->where('fiscalExceptions.0.reservationNumber', $reservation->reservation_number)
                ->where('fiscalExceptions.0.reason', 'Departed before VFMS filing worked')
                ->where('fiscalExceptions.1.kind', 'Cancellation fee')->where('fiscalExceptions.1.reason', 'Late cancellation');
        });
})->with([
    [OrganizationRole::Owner, true],
    [OrganizationRole::PropertyManager, true],
    [OrganizationRole::Finance, true],
    [OrganizationRole::Auditor, false],
    [OrganizationRole::GroupOperationsManager, false],
]);

// protects=closing one stay cannot resolve its neighbours and terminal calls are never dispatched (#176); fails_when=another stay changes or the sweeper requeues closed calls; no_claim=worker infrastructure.
test('closing a stay leaves other stays untouched and never dispatches its superseded calls', function () {
    $tenants = twoTenants();
    $tenant = $tenants->a;
    $reservation = closeUnfiledStay($tenant);
    $other = inTenant($tenant->property, function () use ($reservation) {
        $other = $reservation->replicate();
        $other->reservation_number = 'R-NEIGHBOUR-176';
        $other->save();
        foreach (OutboxMessage::query()->where('subject_id', $reservation->id)->get() as $message) {
            $copy = $message->replicate();
            $copy->forceFill(['subject_id' => $other->id, 'stream_key' => hash('sha256', $other->id), 'idempotency_key' => $message->idempotency_key.':other'])->save();
        }

        return $other;
    });
    $foreign = closeUnfiledStay($tenants->b);
    Queue::fake();
    $this->actingAs($tenant->owner)->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'Departed before VFMS filing worked',
    ])->assertSessionHasNoErrors();
    inTenant($tenant->property, function () use ($reservation, $other) {
        foreach (OutboxMessage::query()->where('subject_id', $reservation->id)->get() as $message) {
            app(DeliveryEngine::class)->deliver($message->id);
            expect($message->fresh()->status)->toBe('superseded');
        }
        expect(OutboxMessage::query()->where('subject_id', $other->id)->orderBy('sequence')->pluck('status')->all())->toBe(['rejected', 'blocked']);
    });
    expect(app(OutboxSweeper::class)->sweep())->toBe(0);
    Queue::assertNothingPushed();
    expect(DB::table('outbox_messages')->where('subject_id', $foreign->id)->orderBy('sequence')->pluck('status')->all())->toBe(['rejected', 'blocked']);
});

// protects=an audit storage failure rolls back every status and the exception together (#176); fails_when=partial resolution survives a failing final audit; no_claim=database disaster recovery.
test('close without filing rolls back all messages if its final audit cannot be stored', function () {
    $tenant = twoTenants()->a;
    $reservation = closeUnfiledStay($tenant);
    Activity::creating(function ($activity) {
        if ($activity->event === 'closed_without_filing') {
            throw new RuntimeException('Audit unavailable');
        }
    });
    $this->actingAs($tenant->owner)->post(route('reservations.vfms.close-without-filing', $reservation), [
        '_property' => $tenant->property->id, 'reason' => 'Departed before VFMS filing worked',
    ])->assertSessionHasErrors('vfms');
    inTenant($tenant->property, function () use ($reservation) {
        expect(OutboxMessage::query()->where('subject_id', $reservation->id)->orderBy('sequence')->pluck('status')->all())->toBe(['rejected', 'blocked'])
            ->and($reservation->fresh()->getAttribute('fiscal_exception_reason'))->toBeNull();
    });
    expect(DB::table('activity_log')->where('event', 'outbox_recovery')->exists())->toBeFalse();
});
