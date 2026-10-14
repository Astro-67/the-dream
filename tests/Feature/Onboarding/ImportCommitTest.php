<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Onboarding\Actions\CommitImport;
use App\Domains\Onboarding\Actions\DryRunImport;
use App\Domains\Onboarding\Enums\ImportJobStatus;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Rates\Models\RateRestriction;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Actions\GoLive;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\ImportForm;

beforeEach(fn () => Storage::fake('local'));

test('commit creates a flagged reservation guest folio and inventory and replay changes nothing', function () {
    [$owner, , $property] = organizationWithOwner();
    $job = inTenant($property, function () use ($owner) {
        BookingForm::details($owner);

        return app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
    });
    $this->actingAs($owner)->post(route('imports.commit', $job), ['_property' => $property->id])
        ->assertSessionHasNoErrors()->assertRedirect(route('imports.show', $job));
    inTenant($property, function () use ($owner, $job) {
        $reservation = Reservation::query()->sole();
        expect($reservation->imported)->toBeTrue()
            ->and($reservation->source_reference)->toBe('OLD-001')
            ->and($reservation->source)->toBe('import')
            ->and($reservation->priceLines()->count())->toBe(2)
            ->and(Folio::query()->count())->toBe(1)
            ->and(Guest::query()->sole()->name)->toBe('Amina Guest')
            ->and(Booker::query()->findOrFail($reservation->booker_id)->email)->toBe('booker@example.test')
            ->and(ReservationGuest::query()->sole()->reservation_id)->toBe($reservation->id)
            ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(2)
            ->and($job->fresh()->commit_results)->toEqual(['created' => 1, 'duplicate' => 0, 'refused' => 0]);
        $before = $job->rows()->sole()->commit_result;
        app(CommitImport::class)->handle($owner, $job);
        $repeat = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        app(CommitImport::class)->handle($owner, $repeat);
        expect(Reservation::query()->count())->toBe(1)
            ->and(Guest::query()->count())->toBe(1)
            ->and(Booker::query()->count())->toBe(2)
            ->and($job->rows()->sole()->commit_result)->toEqual($before)
            ->and($repeat->fresh()->commit_results)->toEqual(['created' => 0, 'duplicate' => 1, 'refused' => 0]);
    });
    $this->get(route('imports.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('importJob.status', 'committed')->where('importJob.commit_results.created', 1)
        ->where('rows.data.0.commit_result.status', 'created'));
});

test('inventory refusal rolls back only that row and stores its errors', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([
            ImportForm::row(), ImportForm::row(['source_reference' => 'OLD-002']),
        ]));
        app(CommitImport::class)->handle($owner, $job);
        expect($job->fresh()->commit_results)->toEqual(['created' => 1, 'duplicate' => 0, 'refused' => 1])
            ->and($job->rows()->get()[1]->commit_result['errors'])->not->toBeEmpty()
            ->and(Reservation::query()->count())->toBe(1)
            ->and(Booker::query()->count())->toBe(2)
            ->and(Guest::query()->count())->toBe(1)
            ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(2)
            ->and(Activity::query()->where('subject_id', $job->id)->where('event', 'refused')->exists())->toBeTrue();
    });
});

test('commit rechecks dates rates eligibility and restrictions rather than replaying a stale quote', function (string $change) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $property, $change) {
        $details = BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        match ($change) {
            'dates' => $property->update(['business_date' => '2026-12-11']),
            'rates' => RatePrice::query()->delete(),
            'eligibility' => RatePlan::query()->update(['deactivated_at' => now()]),
            'restriction' => RateRestriction::query()->create([
                'rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'],
                'date' => '2026-12-10', 'min_stay' => 1, 'closed_to_arrival' => false, 'stop_sell' => true,
            ]),
        };
        app(CommitImport::class)->handle($owner, $job);
        expect($job->rows()->sole()->commit_result['status'])->toBe('refused')
            ->and(Reservation::query()->count())->toBe(0)
            ->and(Guest::query()->count())->toBe(0)
            ->and(Booker::query()->count())->toBe(1);
    });
})->with(['dates', 'rates', 'eligibility', 'restriction']);

test('commit prices at current rates and does not reprice on replay', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        RatePrice::query()->update(['base_price' => 20000]);
        app(CommitImport::class)->handle($owner, $job);
        $amounts = Reservation::query()->sole()->priceLines()->pluck('amount')->all();
        expect($amounts)->toBe([21000, 21000]);
        RatePrice::query()->update(['base_price' => 30000]);
        app(CommitImport::class)->handle($owner, $job);
        expect(Reservation::query()->sole()->priceLines()->pluck('amount')->all())->toBe($amounts);
    });
});

test('separate clean dry runs with the same reference commit once even when inventory is full', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        BookingForm::details($owner);
        $first = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        $second = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        app(CommitImport::class)->handle($owner, $first);
        app(CommitImport::class)->handle($owner, $second);
        expect($second->rows()->sole()->commit_result['status'])->toBe('duplicate')
            ->and(Reservation::query()->count())->toBe(1)
            ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(2);
    });
});

test('an interrupted commit resumes without changing completed row outcomes', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        BookingForm::details($owner);
        $first = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        app(CommitImport::class)->handle($owner, $first);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([
            ImportForm::row(), ImportForm::row(['source_reference' => 'OLD-002']),
        ]));
        $saved = $first->rows()->sole()->commit_result;
        $job->rows()->firstOrFail()->update(['commit_result' => $saved]);
        $this->actingAs($owner)->get(route('imports.show', $job))->assertInertia(fn (Assert $page) => $page
            ->where('importJob.status', 'dry_run')->where('importJob.completed_rows_count', 1)
            ->where('rows.data.0.commit_result.status', 'created'));
        app(CommitImport::class)->handle($owner, $job);
        expect($job->rows()->firstOrFail()->commit_result)->toEqual($saved)
            ->and($job->fresh()->commit_results)->toEqual(['created' => 1, 'duplicate' => 0, 'refused' => 1])
            ->and(Reservation::query()->count())->toBe(1);
    });
});

test('commit enters its own property context and permits the same reference at another property', function () {
    $t = twoTenants();
    $jobs = [];
    foreach ($t->a->properties as $property) {
        $jobs[] = inTenant($property, function () use ($t) {
            BookingForm::details($t->a->owner);

            return app(DryRunImport::class)->handle($t->a->owner, ImportForm::csv([ImportForm::row()]));
        });
    }
    inTenant($t->a->organization, function () use ($t, $jobs) {
        foreach ($jobs as $job) {
            app(CommitImport::class)->handle($t->a->owner, $job);
        }
        expect(Reservation::query()->where('source_reference', 'OLD-001')->count())->toBe(2);
    });
    foreach ($t->a->properties as $property) {
        expect(inTenant($property, fn () => Reservation::query()->sole()->property_id))->toBe($property->id);
    }
});

test('an invalid dry run refuses the whole commit before creating anything', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([
            ImportForm::row(), ImportForm::row(['source_reference' => 'OLD-002', 'status' => 'checked-in']),
        ]));
        expect(fn () => app(CommitImport::class)->handle($owner, $job))->toThrow(ValidationException::class);
        expect(Reservation::query()->count())->toBe(0)->and($job->fresh()->status)->toBe(ImportJobStatus::DryRun);
    });
});

test('Zanzibar imported created events are deferred until go-live once with live business date and actor', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup, 'taxes_reviewed_at' => now()])->save();
    inTenant($property, function () use ($owner, $property) {
        BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        app(CommitImport::class)->handle($owner, $job);
        expect(ReservationLifecycleEvent::query()->count())->toBe(0);
        $property->update(['business_date' => '2026-12-01']);
        app(GoLive::class)->handle($owner, $property);
        app(GoLive::class)->handle($owner, $property);
        $event = ReservationLifecycleEvent::query()->sole();
        expect($event->type)->toBe('created')->and($event->test)->toBeFalse()
            ->and($event->business_date->toDateString())->toBe('2026-12-01')
            ->and($event->actor_id)->toBe($owner->id)
            ->and($event->snapshot['imported'])->toBeTrue()
            ->and($event->snapshot['source_reference'])->toBe('OLD-001')
            ->and($event->snapshot['source'])->toBe('import');
    });
});

test('import checklist and deferred lifecycle writes belong only to the property going live', function () {
    $t = twoTenants();
    foreach ($t->a->properties as $property) {
        $property->forceFill(['status' => PropertyStatus::Setup, 'jurisdiction_profile' => 'zanzibar', 'taxes_reviewed_at' => now()])->save();
        inTenant($property, function () use ($t) {
            BookingForm::details($t->a->owner);
            $job = app(DryRunImport::class)->handle($t->a->owner, ImportForm::csv([ImportForm::row()]));
            app(CommitImport::class)->handle($t->a->owner, $job);
        });
    }
    $this->actingAs($t->a->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.6.completed', true)->where('onboarding.items.7.completed', false));
    inTenant($t->a->organization, fn () => app(GoLive::class)->handle($t->a->owner, $t->a->property));
    expect(inTenant($t->a->property, fn () => ReservationLifecycleEvent::query()->count()))->toBe(1)
        ->and(inTenant($t->a->otherProperty, fn () => ReservationLifecycleEvent::query()->count()))->toBe(0);
});

test('mainland setup and already live Zanzibar imports record created events at commit', function (string $profile, PropertyStatus $status, bool $test) {
    [$owner, , $property] = organizationWithOwner(['jurisdiction_profile' => $profile]);
    $property->forceFill(['status' => $status])->save();
    inTenant($property, function () use ($owner, $test) {
        BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        app(CommitImport::class)->handle($owner, $job);
        expect(ReservationLifecycleEvent::query()->sole()->test)->toBe($test);
    });
})->with([
    ['mainland_tanzania', PropertyStatus::Setup, true],
    ['zanzibar', PropertyStatus::Live, false],
]);

test('commit requires manager permission and property intent', function () {
    $t = twoTenants();
    $job = inTenant($t->a->property, function () use ($t) {
        BookingForm::details($t->a->owner);

        return app(DryRunImport::class)->handle($t->a->owner, ImportForm::csv([ImportForm::row()]));
    });
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))
        ->post(route('imports.commit', $job), ['_property' => $t->a->property->id])->assertForbidden();
    $this->actingAs($t->a->owner)->post(route('imports.commit', $job))->assertSessionHasErrors('_property');
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->otherProperty->id])
        ->post(route('imports.commit', $job), ['_property' => $t->a->otherProperty->id])->assertNotFound();
    expect(inTenant($t->a->property, fn () => Reservation::query()->count()))->toBe(0);
});
