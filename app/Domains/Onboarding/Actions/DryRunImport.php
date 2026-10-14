<?php

namespace App\Domains\Onboarding\Actions;

use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Onboarding\ReservationImportCsv;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DryRunImport
{
    public function handle(?User $actor, UploadedFile $file): ImportJob
    {
        return Audit::validationRefusal($actor, new ImportJob, 'Reservation import dry run refused', function () use ($actor, $file) {
            $rows = app(ReservationImportCsv::class)->rows($file);
            $path = $file->store('reservation-imports', 'local');
            if ($path === false) {
                throw ValidationException::withMessages(['file' => __('The CSV could not be stored. Try again.')]);
            }
            try {
                return DB::transaction(function () use ($actor, $file, $path, $rows) {
                    $job = ImportJob::query()->create(['uploaded_by' => $actor?->id, 'file_path' => $path, 'original_name' => $file->getClientOriginalName()]);
                    foreach ($rows as $row) {
                        $job->rows()->create($row);
                    }
                    Audit::record($actor, 'dry_run', $job, 'Reservation import dry run completed', ['rows' => count($rows)]);

                    return $job->refresh();
                });
            } catch (Throwable $error) {
                Storage::disk('local')->delete($path);

                throw $error;
            }
        });
    }
}
