<?php

namespace App\Http\Controllers\Web\Onboarding;

use App\Domains\Onboarding\Actions\CommitImport;
use App\Domains\Onboarding\Actions\DryRunImport;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Onboarding\ReservationImportCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CommitImportRequest;
use App\Http\Requests\Onboarding\ImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as DownloadResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('create', ImportJob::class);

        return Inertia::render('onboarding/import', ['importJob' => null, 'rows' => null, 'copy' => $this->copy()]);
    }

    public function template(): DownloadResponse
    {
        Gate::authorize('create', ImportJob::class);

        return response(implode(',', ReservationImportCsv::COLUMNS)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="future-reservations.csv"',
        ]);
    }

    public function store(ImportRequest $request, DryRunImport $dryRun): RedirectResponse
    {
        $job = $dryRun->handle($request->user(), $request->file('file'));

        return to_route('imports.show', $job);
    }

    public function show(ImportJob $importJob): Response
    {
        Gate::authorize('view', $importJob);
        $importJob->loadCount([
            'rows',
            'rows as valid_rows_count' => fn ($query) => $query->where('status', 'valid'),
            'rows as invalid_rows_count' => fn ($query) => $query->where('status', 'invalid'),
            'rows as duplicate_rows_count' => fn ($query) => $query->where('status', 'duplicate'),
            'rows as completed_rows_count' => fn ($query) => $query->whereNotNull('commit_result'),
        ]);

        return Inertia::render('onboarding/import', [
            'importJob' => $importJob->only(['id', 'original_name', 'status', 'commit_results', 'rows_count', 'valid_rows_count', 'invalid_rows_count', 'duplicate_rows_count', 'completed_rows_count']),
            'rows' => $importJob->rows()->paginate(50, ['id', 'row_number', 'source_reference', 'status', 'errors', 'commit_result'])->withQueryString(),
            'copy' => $this->copy(),
        ]);
    }

    public function commit(CommitImportRequest $request, ImportJob $importJob, CommitImport $commit): RedirectResponse
    {
        $commit->handle($request->user(), $importJob);

        return to_route('imports.show', $importJob);
    }

    /** @return array<string, string> */
    private function copy(): array
    {
        return [
            'title' => __('Import future reservations'),
            'description' => __('Check a CSV from your previous system before creating any reservations.'),
            'template' => __('Download CSV template'),
            'instructions' => __("Use this property's room type and rate plan codes, dates in YYYY-MM-DD format, and status confirmed. Rates must be configured for every night. Past and in-house stays cannot be imported."),
            'file' => __('Reservation CSV'),
            'limits' => __('CSV only. Maximum 2 MB and 1,000 reservations.'),
            'run' => __('Run dry run'),
            'results' => __('Dry-run results'),
            'commitResults' => __('Import results'),
            'commit' => __('Commit import'),
            'resume' => __('Resume import'),
            'partialNotice' => __('Some rows already have stored outcomes. Resume the import to process the remaining rows; completed rows will not change.'),
            'commitNotice' => __('Commit creates reservations and takes inventory at current rates. Existing source references are skipped. Refused rows create nothing.'),
            'committedNotice' => __('Import complete. These stored results will not change if you submit this import again. To retry refused rows, correct the CSV and run a new dry run.'),
            'invalidNotice' => __('Correct the invalid rows and run a new dry run before committing.'),
            'created' => __('Created'),
            'refused' => __('Refused'),
            'reservationNumber' => __('Reservation number'),
            'noChanges' => __('No reservations have been created. Correct the errors in your CSV and upload it again.'),
            'row' => __('CSV row'),
            'reference' => __('Source reference'),
            'status' => __('Result'),
            'errors' => __('Errors'),
            'valid' => __('Valid'),
            'invalid' => __('Invalid'),
            'duplicate' => __('Duplicate'),
            'ready' => __('Ready for import'),
            'empty' => __('Download the template, fill in future reservations, then upload it for a dry run.'),
        ];
    }
}
