<?php

use App\Domains\Onboarding\Actions\DryRunImport;
use App\Domains\Onboarding\Enums\ImportJobStatus;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'imports.commit' => RouteCoverage::attempt(function (TwoTenants $t) {
        Storage::fake('local');
        $job = inTenant($t->b->property, fn () => app(DryRunImport::class)->handle($t->b->owner, ImportForm::csv([ImportForm::row()])));
        $response = $this->actingAs($t->a->owner)->post(route('imports.commit', $job), [
            '_property' => $t->a->property->id,
        ])->assertNotFound();
        expect(inTenant($t->b->property, fn () => $job->fresh()->status))->toBe(ImportJobStatus::DryRun)
            ->and(inTenant($t->b->property, fn () => Reservation::query()->count()))->toBe(0);

        return $response;
    }),
    'imports.index' => RouteCoverage::visit(),
    'imports.template' => RouteCoverage::attempt(function (TwoTenants $t) {
        $response = $this->actingAs($t->a->owner)->get(route('imports.template', ['property' => $t->b->property->id]))->assertOk();
        expect(str_getcsv(trim($response->getContent()), escape: ''))->toBe(array_keys(ImportForm::row()));

        return $response;
    }),
    'imports.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        Storage::fake('local');
        $response = $this->actingAs($t->a->owner)->post(route('imports.store'), [
            '_property' => $t->b->property->id,
            'file' => ImportForm::csv([ImportForm::row()]),
        ])->assertForbidden();
        expect(inTenant($t->b->property, fn () => ImportJob::query()->count()))->toBe(0)
            ->and(inTenant($t->b->property, fn () => Reservation::query()->count()))->toBe(0);

        return $response;
    }),
    'imports.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        Storage::fake('local');
        $job = inTenant($t->b->property, fn () => app(DryRunImport::class)->handle($t->b->owner, ImportForm::csv([ImportForm::row()])));
        $responses = [];
        foreach ($t->a->members() as $member) {
            $responses[] = $this->actingAs($member)->get(route('imports.show', $job))->assertNotFound();
        }

        return $responses;
    }),
];
