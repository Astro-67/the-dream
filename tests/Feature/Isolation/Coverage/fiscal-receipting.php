<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\Actions\SaveVfmsCredentials;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Outbox\DrainOutboxMessage;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/** Bravo saves its own credentials first, so an attack has something to overwrite. */
$bravoCredentials = function (TwoTenants $t): void {
    Http::fake([config('services.vfms.live_url').'currencyExchangeRate/' => Http::response([], 401)]);
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
    'issued-documents.corrections.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $document = inTenant($t->b->property, function () use ($t) {
            $stay = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
            $message = OutboxMessage::query()->create([
                'integration' => 'vfms', 'subject_type' => $stay->getMorphClass(), 'subject_id' => $stay->id,
                'sequence' => 1, 'stream_key' => hash('sha256', $stay->id), 'idempotency_key' => 'foreign-document:'.$stay->id,
                'event' => 'services', 'payload' => [], 'status' => 'delivered',
            ]);

            return IssuedDocument::query()->create([
                'outbox_message_id' => $message->id, 'reservation_id' => $stay->id,
                'type' => 'services_receipt', 'number' => 'BRAVO-ISSUED', 'mode' => 'live', 'issued_at' => now(),
            ]);
        });
        $before = DB::table('issued_documents')->where('id', $document->id)->sole();
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('issued-documents.corrections.store', $document), [
                '_property' => $t->a->property->id, 'replacement_id' => $document->id, 'reason' => 'Foreign document attack', 'confirmed' => true,
            ])->assertNotFound();
        }
        expect(DB::table('issued_documents')->where('id', $document->id)->sole())->toEqual($before)
            ->and(DB::table('document_corrections')->where('property_id', $t->b->property->id)->count())->toBe(0)
            ->and(DB::table('outbox_messages')->where('property_id', $t->b->property->id)->where('event', 'errorSave')->count())->toBe(0);

        return $responses;
    }),
    'document-corrections.refresh' => RouteCoverage::attempt(function (TwoTenants $t) {
        Http::fake();
        Queue::fake([DrainOutboxMessage::class]);
        $correction = inTenant($t->b->property, function () use ($t) {
            [, $old, $new] = correctionDocuments($t->b);
            $message = OutboxMessage::query()->where('property_id', $t->b->property->id)->firstOrFail();

            return DocumentCorrection::query()->create([
                'original_document_id' => $old->id, 'replacement_document_id' => $new->id, 'original_number' => $old->number,
                'replacement_number' => $new->number, 'actor_id' => $t->b->owner->id, 'reason' => 'Bravo reason', 'mode' => 'live',
                'unit_id' => 232, 'outbox_message_id' => $message->id,
            ]);
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('document-corrections.refresh', $correction), ['_property' => $t->a->property->id])->assertNotFound();
        }
        expect(DB::table('correction_decision_reads')->count())->toBe(0)
            ->and(DB::table('correction_decisions')->count())->toBe(0);
        Http::assertNothingSent();

        return $responses;
    }),
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
    'reservations.general-receipt.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $institution = ['paying_institution_type' => 'government', 'paying_institution_name' => 'Bravo Ministry', 'paying_institution_zrb_number' => 'WA2400117'];
        // A foreign organization's stay, and a sibling property's stay in Alpha's own organization.
        [$foreign, $foreignExtra] = inTenant($t->b->property, function () use ($t, $institution) {
            $stay = app(CreateReservation::class)->handle($t->b->owner, [...BookingForm::details($t->b->owner), ...$institution]);
            $stay->update(['status' => 'checked_in']);

            return [$stay, app(PostExtraCharge::class)->handle($t->b->owner, Folio::query()->sole(), ['category' => 'bar', 'description' => 'BRAVO BAR', 'quantity' => 1, 'unit_price' => 500])];
        });
        [$sibling, $siblingExtra] = inTenant($t->a->otherProperty, function () use ($t, $institution) {
            $stay = app(CreateReservation::class)->handle($t->a->owner, [...BookingForm::details($t->a->owner), ...$institution]);
            $stay->update(['status' => 'checked_in']);

            return [$stay, app(PostExtraCharge::class)->handle($t->a->owner, Folio::query()->sole(), ['category' => 'bar', 'description' => 'SIBLING BAR', 'quantity' => 1, 'unit_price' => 500])];
        });
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->post(route('reservations.general-receipt.store', $foreign), ['_property' => $t->a->property->id])->assertNotFound();
        }
        // Stale intent: a form opened at Alpha's main property runs there, so the sibling's stay is not found.
        $responses[] = $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->otherProperty->id])
            ->post(route('reservations.general-receipt.store', $sibling), ['_property' => $t->a->property->id])->assertNotFound();
        expect(inTenant($t->b->property, fn () => $foreignExtra->fresh()->general_receipt_id))->toBeNull()
            ->and(inTenant($t->a->otherProperty, fn () => $siblingExtra->fresh()->general_receipt_id))->toBeNull()
            ->and(inTenant($t->b->property, fn () => OutboxMessage::query()->where('event', 'generalReceipt')->count()))->toBe(0);

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
    'properties.vfms.check' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoCredentials) {
        $bravoCredentials($t);
        $this->actingAs($t->b->owner)->post(route('properties.vfms.check', $t->b->property->id), ['_property' => $t->b->property->id])
            ->assertSessionHasNoErrors();
        $this->travel(61)->seconds();
        $responses = [
            $this->actingAs($t->a->owner)->post(route('properties.vfms.check', $t->b->property->id), ['_property' => $t->a->property->id])->assertNotFound(),
            $this->actingAs($t->a->owner)->post(route('properties.vfms.check', $t->b->property->id), ['_property' => $t->b->property->id])->assertForbidden(),
        ];
        Http::assertSentCount(1);
        expect(VfmsCredential::for($t->b->property)->zra_check)->toBe('refused');

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
