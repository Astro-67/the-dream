<?php

namespace App\Domains\Onboarding\Actions;

use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Onboarding\Models\ImportRow;
use App\Domains\Onboarding\ReservationImportCsv;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitImport
{
    public function handle(User $actor, ImportJob $job): ImportJob
    {
        return Tenancy::within(TenantContext::fromIds($job->organization_id, $job->property_id), function () use ($actor, $job) {
            return Audit::validationRefusal($actor, $job, 'Reservation import commit refused', function () use ($actor, $job) {
                $job = ImportJob::query()->findOrFail($job->id);
                if ($job->status === 'committed') {
                    return $job;
                }
                if ($job->rows()->where('status', 'invalid')->exists()) {
                    throw ValidationException::withMessages(['import' => __('Correct the invalid rows and run a new dry run before committing.')]);
                }
                foreach ($job->rows()->pluck('id') as $rowId) {
                    DB::transaction(function () use ($actor, $job, $rowId) {
                        ImportJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
                        $row = $job->rows()->whereKey($rowId)->lockForUpdate()->firstOrFail();
                        if ($row->commit_result !== null) {
                            return;
                        }
                        $result = $this->commitRow($actor, $row);
                        $row->update(['commit_result' => $result]);
                        Audit::record($actor, $result['status'] === 'refused' ? 'refused' : 'row_committed', $job, 'Reservation import row completed', [
                            'row_number' => $row->row_number, 'result' => $result['status'], 'errors' => $result['errors'] ?? [],
                        ]);
                    }, attempts: 3);
                }

                return DB::transaction(function () use ($actor, $job) {
                    $locked = ImportJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status === 'committed') {
                        return $locked;
                    }
                    $counts = ['created' => 0, 'duplicate' => 0, 'refused' => 0];
                    foreach ($locked->rows()->get() as $row) {
                        $counts[$row->commit_result['status']]++;
                    }
                    $locked->update(['status' => 'committed', 'commit_results' => $counts]);
                    Audit::record($actor, 'committed', $locked, 'Reservation import committed', $counts);

                    return $locked;
                }, attempts: 3);
            });
        });
    }

    /** @return array<string, mixed> */
    private function commitRow(User $actor, ImportRow $row): array
    {
        $existing = Reservation::query()->where('source_reference', $row->source_reference)->lockForUpdate()->first();
        if ($existing !== null || $row->status === 'duplicate') {
            return ['status' => 'duplicate', 'reservation_number' => $existing?->reservation_number];
        }

        try {
            return DB::transaction(function () use ($actor, $row) {
                $checked = app(ReservationImportCsv::class)->validateRow(
                    array_map(fn (string $column) => $row->raw_data[$column] ?? '', ReservationImportCsv::COLUMNS),
                    $row->row_number,
                );
                if ($checked['status'] !== 'valid') {
                    throw ValidationException::withMessages($checked['errors']);
                }
                $data = $checked['validated_data'];
                $booker = app(CreateBooker::class)->handle($actor, $data['booker']);
                $reservation = app(CreateReservation::class)->handle($actor, [
                    ...$data['reservation'], 'booker_id' => $booker->id, 'imported' => true,
                ]);
                app(AttachReservationGuest::class)->handle($actor, $reservation, $data['guest']);

                return ['status' => 'created', 'reservation_number' => $reservation->reservation_number];
            });
        } catch (ValidationException|UniqueConstraintViolationException $error) {
            $existing = Reservation::query()->where('source_reference', $row->source_reference)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['status' => 'duplicate', 'reservation_number' => $existing->reservation_number];
            }
            if ($error instanceof UniqueConstraintViolationException) {
                throw $error;
            }

            return ['status' => 'refused', 'errors' => $error->errors()];
        }
    }
}
