<?php

use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Onboarding\Actions\DryRunImport;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

function importDryRunCsv(array $rows): UploadedFile
{
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, ['source_reference', 'room_type_code', 'rate_plan_code', 'arrival', 'departure', 'adults', 'children', 'booker_name', 'booker_email', 'guest_name', 'guest_email', 'status', 'restriction_override_reason'], escape: '');
    foreach ($rows as $row) {
        fputcsv($stream, array_values($row), escape: '');
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return UploadedFile::fake()->createWithContent('future-reservations.csv', $csv);
}

function importDryRunRow(array $overrides = []): array
{
    return [
        'source_reference' => 'OLD-001', 'room_type_code' => 'DLX', 'rate_plan_code' => 'BB',
        'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => '2', 'children' => '1',
        'booker_name' => 'Travel Booker', 'booker_email' => 'booker@example.test',
        'guest_name' => 'Amina Guest', 'guest_email' => 'amina@example.test',
        'status' => 'confirmed', 'restriction_override_reason' => '',
        ...$overrides,
    ];
}

test('dry run retains the upload and replayable row without creating reservations or guests or taking inventory', function () {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();

    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        $job = app(DryRunImport::class)->handle($owner, importDryRunCsv([importDryRunRow()]));
        $row = $job->rows()->firstOrFail();

        Storage::disk('local')->assertExists($job->file_path);
        expect($job->status)->toBe('dry_run')
            ->and($row->status)->toBe('valid')
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
        $job = app(DryRunImport::class)->handle($owner, importDryRunCsv([
            importDryRunRow(['arrival' => '2026-11-30', 'departure' => '2026-12-12']),
            importDryRunRow(['source_reference' => 'OLD-002']),
        ]));
        $rows = $job->rows()->get();
        expect($rows[0]->status)->toBe('invalid')
            ->and($rows[0]->errors)->toHaveKey('arrival')
            ->and($rows[0]->validated_data)->toBeNull()
            ->and($rows[1]->status)->toBe('valid')
            ->and(Reservation::query()->count())->toBe(0);
    });
});

test('dry run flags source references already imported at this property and repeated within the CSV', function () {
    Storage::fake('local');
    [$owner, $organization, $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $details = BookingForm::details($owner);
        app(CreateReservation::class)->handle($owner, [...$details, 'source_reference' => 'OLD-001']);
        $job = app(DryRunImport::class)->handle($owner, importDryRunCsv([
            importDryRunRow(),
            importDryRunRow(['source_reference' => 'OLD-002']),
            importDryRunRow(['source_reference' => 'OLD-002']),
        ]));
        $rows = $job->rows()->get();
        expect($rows->pluck('status')->all())->toBe(['duplicate', 'valid', 'duplicate'])
            ->and($rows[0]->errors)->toHaveKey('source_reference')
            ->and($rows[2]->errors)->toHaveKey('source_reference')
            ->and($rows[0]->source_reference)->toBe('OLD-001')
            ->and(Reservation::query()->count())->toBe(1);
    });
});
