import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { commit, store, template } from '@/routes/imports';
import type { Paginated } from '@/types';

type ImportRow = {
    id: string;
    row_number: number;
    source_reference: string | null;
    status: 'valid' | 'invalid' | 'duplicate';
    errors: Record<string, string[]>;
    commit_result: {
        status: 'created' | 'duplicate' | 'refused';
        reservation_number?: string | null;
        errors?: Record<string, string[]>;
    } | null;
};

export default function Import({
    importJob,
    rows,
    copy,
}: {
    importJob: {
        id: string;
        original_name: string;
        status: 'dry_run' | 'committed';
        commit_results: {
            created: number;
            duplicate: number;
            refused: number;
        } | null;
        valid_rows_count: number;
        invalid_rows_count: number;
        duplicate_rows_count: number;
        completed_rows_count: number;
    } | null;
    rows: Paginated<ImportRow> | null;
    copy: Record<string, string>;
}) {
    return (
        <>
            <Head title={copy.title} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <h1 className="text-2xl font-semibold">{copy.title}</h1>
                        <p className="text-sm text-muted-foreground">
                            {copy.description}
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <a href={template().url}>{copy.template}</a>
                    </Button>
                </header>
                <Form
                    {...store.form()}
                    encType="multipart/form-data"
                    className="max-w-2xl space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors._property} />
                            <p className="text-sm text-muted-foreground">
                                {copy.instructions}
                            </p>
                            <div className="space-y-2">
                                <Label htmlFor="file">{copy.file}</Label>
                                <Input
                                    id="file"
                                    name="file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    required
                                    aria-describedby="file-limits file-error"
                                    aria-invalid={!!errors.file}
                                />
                                <p
                                    id="file-limits"
                                    className="text-sm text-muted-foreground"
                                >
                                    {copy.limits}
                                </p>
                                <InputError
                                    id="file-error"
                                    message={errors.file}
                                />
                            </div>
                            <Button disabled={processing}>
                                <span className="inline-flex size-4 items-center justify-center">
                                    {processing && <Spinner />}
                                </span>
                                {copy.run}
                            </Button>
                        </>
                    )}
                </Form>
                {importJob && rows ? (
                    <section
                        className="space-y-4"
                        aria-labelledby="import-results"
                    >
                        <div className="space-y-2">
                            <h2
                                id="import-results"
                                className="text-xl font-semibold"
                            >
                                {importJob.status === 'committed'
                                    ? copy.commitResults
                                    : copy.results}
                            </h2>
                            <p className="text-sm break-all">
                                {importJob.original_name}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {importJob.status === 'committed'
                                    ? copy.committedNotice
                                    : importJob.completed_rows_count > 0
                                      ? copy.partialNotice
                                      : copy.noChanges}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <StatusChip tone="success">
                                    {importJob.commit_results
                                        ? copy.created
                                        : copy.valid}
                                    :{' '}
                                    {importJob.commit_results?.created ??
                                        importJob.valid_rows_count}
                                </StatusChip>
                                <StatusChip tone="error">
                                    {importJob.commit_results
                                        ? copy.refused
                                        : copy.invalid}
                                    :{' '}
                                    {importJob.commit_results?.refused ??
                                        importJob.invalid_rows_count}
                                </StatusChip>
                                <StatusChip tone="warning">
                                    {copy.duplicate}:{' '}
                                    {importJob.commit_results?.duplicate ??
                                        importJob.duplicate_rows_count}
                                </StatusChip>
                            </div>
                        </div>
                        {importJob.status === 'dry_run' && (
                            <Form
                                {...commit.form(importJob.id)}
                                className="max-w-2xl space-y-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <PropertyIntent />
                                        <p
                                            id="commit-notice"
                                            className="text-sm text-muted-foreground"
                                        >
                                            {importJob.invalid_rows_count > 0
                                                ? copy.invalidNotice
                                                : copy.commitNotice}
                                        </p>
                                        <InputError message={errors.import} />
                                        <InputError
                                            message={errors._property}
                                        />
                                        <Button
                                            disabled={
                                                processing ||
                                                importJob.invalid_rows_count > 0
                                            }
                                            aria-describedby="commit-notice"
                                        >
                                            <span className="inline-flex size-4 items-center justify-center">
                                                {processing && <Spinner />}
                                            </span>
                                            {importJob.completed_rows_count > 0
                                                ? copy.resume
                                                : copy.commit}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead numeric>{copy.row}</TableHead>
                                    <TableHead>{copy.reference}</TableHead>
                                    <TableHead>{copy.status}</TableHead>
                                    {importJob.status === 'committed' && (
                                        <TableHead>
                                            {copy.reservationNumber}
                                        </TableHead>
                                    )}
                                    <TableHead>{copy.errors}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell numeric>
                                            {row.row_number}
                                        </TableCell>
                                        <TableCell>
                                            <Identifier
                                                value={
                                                    row.source_reference ?? ''
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    (row.commit_result
                                                        ?.status ??
                                                        row.status) ===
                                                        'valid' ||
                                                    row.commit_result
                                                        ?.status === 'created'
                                                        ? 'success'
                                                        : (row.commit_result
                                                                ?.status ??
                                                                row.status) ===
                                                            'duplicate'
                                                          ? 'warning'
                                                          : 'error'
                                                }
                                            >
                                                {
                                                    copy[
                                                        row.commit_result
                                                            ?.status ??
                                                            row.status
                                                    ]
                                                }
                                            </StatusChip>
                                        </TableCell>
                                        {importJob.status === 'committed' && (
                                            <TableCell>
                                                {row.commit_result
                                                    ?.reservation_number && (
                                                    <Identifier
                                                        value={
                                                            row.commit_result
                                                                .reservation_number
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                        )}
                                        <TableCell className="min-w-64 whitespace-normal">
                                            {Object.keys(
                                                row.commit_result
                                                    ? (row.commit_result
                                                          .errors ?? {})
                                                    : row.errors,
                                            ).length === 0 ? (
                                                row.commit_result ? null : (
                                                    copy.ready
                                                )
                                            ) : (
                                                <ul className="list-disc space-y-1 pl-4">
                                                    {Object.entries(
                                                        row.commit_result
                                                            ? (row.commit_result
                                                                  .errors ?? {})
                                                            : row.errors,
                                                    ).flatMap(
                                                        ([field, messages]) =>
                                                            messages.map(
                                                                (
                                                                    message,
                                                                    index,
                                                                ) => (
                                                                    <li
                                                                        key={`${field}-${index}`}
                                                                    >
                                                                        <span className="font-medium">
                                                                            {
                                                                                field
                                                                            }
                                                                            :{' '}
                                                                        </span>
                                                                        {
                                                                            message
                                                                        }
                                                                    </li>
                                                                ),
                                                            ),
                                                    )}
                                                </ul>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={rows} />
                    </section>
                ) : (
                    <EmptyState message={copy.empty} />
                )}
            </div>
        </>
    );
}

Import.layout = {
    breadcrumbs: [{ title: 'Import future reservations', href: '/imports' }],
};
