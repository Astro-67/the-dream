<?php

use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Onboarding\Actions\DryRunImport;
use App\Domains\Onboarding\Enums\ImportJobStatus;
use App\Domains\Onboarding\Enums\ImportRowStatus;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RateRestriction;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\ImportForm;

test('dry run retains the upload and replayable row without creating reservations or guests or taking inventory', function () {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();

    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row()]));
        $row = $job->rows()->firstOrFail();

        Storage::disk('local')->assertExists($job->file_path);
        expect($job->status)->toBe(ImportJobStatus::DryRun)
            ->and($row->status)->toBe(ImportRowStatus::Valid)
            ->and($row->row_number)->toBe(2)
            ->and($row->errors)->toBe([])
            ->and($row->validated_data['reservation'])->toMatchArray([
                'room_type_id' => $details['room_type_id'], 'rate_plan_id' => $details['rate_plan_id'],
                'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => 2, 'children' => 1,
                'source_reference' => 'OLD-001',
            ])
            ->and($row->validated_data['guest'])->toEqual(['name' => 'Amina Guest', 'email' => 'amina@example.test'])
            ->and($row->validated_data['quote']['currency'])->toBe('USD')
            ->and($row->validated_data['quote']['nights'])->toHaveCount(2)
            ->and(Reservation::query()->count())->toBe(0)
            ->and(Guest::query()->count())->toBe(0)
            ->and(Booker::query()->count())->toBe(1)
            ->and((int) InventoryRecord::query()->sum('sold_count'))->toBe(0)
            ->and(Activity::query()->where('subject_id', $job->id)->where('event', 'dry_run')->exists())->toBeTrue();
    });
});

test('dry run rejects past stays using the property business date while retaining other rows', function () {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    $property->forceFill(['business_date' => '2026-12-01'])->save();

    inTenant($property, function () use ($owner) {
        BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([
            ImportForm::row(['arrival' => '2026-11-30', 'departure' => '2026-12-12']),
            ImportForm::row(['source_reference' => 'OLD-002']),
        ]));
        $rows = $job->rows()->get();
        expect($rows[0]->status)->toBe(ImportRowStatus::Invalid)
            ->and($rows[0]->errors)->toHaveKey('arrival')
            ->and($rows[0]->validated_data)->toBeNull()
            ->and($rows[1]->status)->toBe(ImportRowStatus::Valid)
            ->and(Reservation::query()->count())->toBe(0);
    });
});

test('dry run flags source references already imported at this property and repeated within the CSV', function () {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        app(CreateReservation::class)->handle($owner, [...$details, 'source_reference' => 'OLD-001']);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([
            ImportForm::row(),
            ImportForm::row(['source_reference' => 'OLD-002']),
            ImportForm::row(['source_reference' => 'OLD-002']),
        ]));
        $rows = $job->rows()->get();
        expect($rows->pluck('status')->all())->toBe([ImportRowStatus::Duplicate, ImportRowStatus::Valid, ImportRowStatus::Duplicate])
            ->and($rows[0]->errors)->toHaveKey('source_reference')
            ->and($rows[2]->errors)->toHaveKey('source_reference')
            ->and($rows[0]->source_reference)->toBe('OLD-001')
            ->and(Reservation::query()->count())->toBe(1);
    });
});

test('dry run names row errors for stays guests and rates', function (array $changes, string $field) {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $changes, $field) {
        BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([ImportForm::row($changes)]));
        $row = $job->rows()->firstOrFail();
        expect($row->status)->toBe(ImportRowStatus::Invalid)
            ->and($row->errors)->toHaveKey($field)
            ->and($row->validated_data)->toBeNull()
            ->and(Reservation::query()->count())->toBe(0);
    });
})->with([
    'in-house' => [['status' => 'in_house'], 'status'],
    'checked in' => [['status' => 'checked_in'], 'status'],
    'missing reference' => [['source_reference' => ''], 'source_reference'],
    'invalid date' => [['arrival' => '2026-02-30'], 'arrival'],
    'departure before arrival' => [['departure' => '2026-12-09'], 'departure'],
    'stay over a year' => [['departure' => '2027-12-12'], 'departure'],
    'no adult' => [['adults' => '0'], 'adults'],
    'negative children' => [['children' => '-1'], 'children'],
    'too many guests' => [['adults' => '3'], 'rate_plan_code'],
    'missing guest' => [['guest_name' => ''], 'guest_name'],
    'invalid guest email' => [['guest_email' => 'not-an-email'], 'guest_email'],
    'missing booker' => [['booker_name' => ''], 'booker_name'],
    'unknown room type' => [['room_type_code' => 'UNKNOWN'], 'room_type_code'],
    'unknown rate plan' => [['rate_plan_code' => 'UNKNOWN'], 'rate_plan_code'],
    'unpriced night' => [['departure' => '2026-12-13'], 'rate_plan_code'],
]);

test('a manager downloads the template uploads a CSV and sees persisted row results', function () {
    Storage::fake('local');
    $t = twoTenants();
    inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $manager = $t->a->member(OrganizationRole::PropertyManager);
    $this->actingAs($manager)->get(route('imports.index'))->assertOk();
    $template = $this->get(route('imports.template'))->assertOk()->assertDownload('future-reservations.csv');
    expect(str_getcsv(trim($template->getContent()), escape: ''))->toBe(array_keys(ImportForm::row()));
    $response = $this->post(route('imports.store'), [
        '_property' => $t->a->property->id,
        'file' => ImportForm::csv([ImportForm::row(), ImportForm::row(['source_reference' => 'OLD-002', 'guest_name' => ''])]),
    ]);
    $job = inTenant($t->a->property, fn () => ImportJob::query()->sole());
    $response->assertRedirect(route('imports.show', $job));
    $this->get(route('imports.show', $job))->assertInertia(fn (Assert $page) => $page
        ->component('onboarding/import')
        ->where('importJob.id', $job->id)
        ->where('importJob.valid_rows_count', 1)
        ->where('importJob.invalid_rows_count', 1)
        ->has('rows.data', 2)
        ->where('rows.data.0.status', 'valid')
        ->has('rows.data.1.errors.guest_name'));
    expect(inTenant($t->a->property, fn () => Reservation::query()->count()))->toBe(0);
});

test('a dry run requires a restriction override rather than storing an unbookable replay payload', function () {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        RateRestriction::query()->create([
            'rate_plan_id' => $details['rate_plan_id'], 'room_type_id' => $details['room_type_id'],
            'date' => '2026-12-10', 'min_stay' => 3, 'closed_to_arrival' => false, 'stop_sell' => false,
        ]);
        $job = app(DryRunImport::class)->handle($owner, ImportForm::csv([
            ImportForm::row(),
            ImportForm::row(['source_reference' => 'OLD-002', 'restriction_override_reason' => 'Honour the previous system reservation']),
        ]));
        $rows = $job->rows()->get();
        expect($rows[0]->status)->toBe(ImportRowStatus::Invalid)
            ->and($rows[0]->errors)->toHaveKey('restriction_override_reason')
            ->and($rows[1]->status)->toBe(ImportRowStatus::Valid)
            ->and($rows[1]->validated_data['reservation']['restriction_override_reason'])->toBe('Honour the previous system reservation');
    });
});

test('CSV structural failures refuse the upload without saving an import job or file', function (string $csv) {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $csv) {
        expect(fn () => app(DryRunImport::class)->handle($owner, UploadedFile::fake()->createWithContent('reservations.csv', $csv)))
            ->toThrow(ValidationException::class);
        expect(ImportJob::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });
})->with([
    'empty file' => [''],
    'wrong headers' => ["reference,arrival\nOLD-001,2026-12-10\n"],
    'no reservations' => [implode(',', array_keys(ImportForm::row()))."\n"],
    'too many rows' => [implode(',', array_keys(ImportForm::row()))."\n".str_repeat("short,row\n", 1001)],
    'non UTF-8 CSV' => [implode(',', array_keys(ImportForm::row()))."\n".implode(',', ImportForm::row(['guest_name' => "\xFF"]))."\n"],
]);

test('import references and room and rate codes are scoped to the selected property even within one organization', function () {
    Storage::fake('local');
    $t = twoTenants();
    inTenant($t->a->otherProperty, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateReservation::class)->handle($t->a->owner, [...$details, 'source_reference' => 'OLD-001']);
        RoomType::query()->findOrFail($details['room_type_id'])->update(['code' => 'OTHER']);
        RatePlan::query()->findOrFail($details['rate_plan_id'])->update(['code' => 'OTHER']);
    });
    inTenant($t->a->property, function () use ($t) {
        BookingForm::details($t->a->owner);
        $job = app(DryRunImport::class)->handle($t->a->owner, ImportForm::csv([
            ImportForm::row(),
            ImportForm::row(['source_reference' => 'OLD-002', 'room_type_code' => 'OTHER', 'rate_plan_code' => 'OTHER']),
        ]));
        $rows = $job->rows()->get();
        expect($rows[0]->status)->toBe(ImportRowStatus::Valid)
            ->and($rows[1]->status)->toBe(ImportRowStatus::Invalid)
            ->and($rows[1]->errors)->toHaveKeys(['room_type_code', 'rate_plan_code']);
    });
    expect(inTenant($t->a->otherProperty, fn () => Reservation::query()->count()))->toBe(1);
});

test('non-managers cannot download templates upload CSVs or inspect dry runs', function () {
    Storage::fake('local');
    $t = twoTenants();
    $job = inTenant($t->a->property, fn () => app(DryRunImport::class)->handle($t->a->owner, ImportForm::csv([ImportForm::row()])));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->get(route('imports.index'))->assertForbidden();
    $this->get(route('imports.template'))->assertForbidden();
    $this->get(route('imports.show', $job))->assertForbidden();
    $this->post(route('imports.store'), ['_property' => $t->a->property->id, 'file' => ImportForm::csv([ImportForm::row()])])->assertForbidden();
    expect(inTenant($t->a->property, fn () => ImportJob::query()->count()))->toBe(1);
});

test('upload validates the file and property intent before storing anything', function (string $case) {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    $file = match ($case) {
        'missing file' => null,
        'wrong extension' => UploadedFile::fake()->createWithContent('reservations.txt', "not a CSV\n"),
        'too large' => UploadedFile::fake()->create('reservations.csv', 2049, 'text/csv'),
        default => ImportForm::csv([ImportForm::row()]),
    };
    $data = ['file' => $file, ...($case === 'missing intent' ? [] : ['_property' => $property->id])];
    $this->actingAs($owner)->post(route('imports.store'), $data)
        ->assertSessionHasErrors($case === 'missing intent' ? '_property' : 'file');
    expect(inTenant($property, fn () => ImportJob::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['missing file', 'wrong extension', 'too large', 'missing intent']);

test('malformed rows have their own errors and blank lines keep CSV row numbers', function () {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        BookingForm::details($owner);
        $content = "\xEF\xBB\xBF".implode(',', array_keys(ImportForm::row()))."\n\nshort,row\n".implode(',', ImportForm::row())."\n";
        $job = app(DryRunImport::class)->handle($owner, UploadedFile::fake()->createWithContent('reservations.csv', $content));
        $rows = $job->rows()->get();
        expect($rows->pluck('row_number')->all())->toBe([3, 4])
            ->and($rows[0]->errors)->toHaveKey('row')
            ->and($rows[1]->status)->toBe(ImportRowStatus::Valid);
    });
});
