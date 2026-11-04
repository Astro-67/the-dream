import { Form, Head, Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusChip, Tag } from '@/components/ui/status-chip';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney, formatTime } from '@/lib/utils';
import { index } from '@/routes/receipts';
import type { Paginated } from '@/types';

const types = {
    non_fiscal: 'Non-fiscal receipt',
    services_receipt: 'ZRA services receipt',
    checkout_report: 'Guest check-out report',
};
const statuses = {
    live: 'Issued Live',
    sandbox: 'Issued Sandbox',
    not_applicable: 'Not applicable',
};
type Document = {
    id: string;
    number: string;
    type: keyof typeof types;
    issuedAt: string;
    guest: string | null;
    amount: number | null;
    currency: string | null;
    zraStatus: keyof typeof statuses;
    mode: 'live' | 'sandbox' | null;
    viewerUrl: string | null;
    localUrl: string | null;
};
type Filters = {
    from?: string;
    to?: string;
    type?: string;
    zra_status?: string;
};

export default function Receipts({
    documents,
    filters,
}: {
    documents: Paginated<Document>;
    filters: Filters;
}) {
    const timezone = usePage().props.tenant.property?.timezone ?? 'UTC';
    return (
        <>
            <Head title="Receipts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Receipts"
                    description="Issued documents for the selected property."
                />
                <Form
                    key={JSON.stringify(filters)}
                    action={index.url()}
                    method="get"
                    transform={(data) =>
                        Object.fromEntries(
                            Object.entries(data).filter(
                                ([, value]) => value !== '' && value !== 'all',
                            ),
                        )
                    }
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-end gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {(['from', 'to'] as const).map((name) => (
                                <div key={name} className="grid gap-2">
                                    <Label htmlFor={name}>
                                        {name === 'from'
                                            ? 'Issued from'
                                            : 'Issued through'}
                                    </Label>
                                    <Input
                                        id={name}
                                        name={name}
                                        type="date"
                                        defaultValue={filters[name]}
                                        aria-invalid={!!errors[name]}
                                        aria-describedby={`${name}-error`}
                                    />
                                    <InputError
                                        id={`${name}-error`}
                                        message={errors[name]}
                                    />
                                </div>
                            ))}
                            {(
                                [
                                    ['type', 'Document type', types],
                                    ['zra_status', 'ZRA status', statuses],
                                ] as const
                            ).map(([name, label, choices]) => (
                                <div key={name} className="grid gap-2">
                                    <Label htmlFor={name}>{label}</Label>
                                    <Select
                                        name={name}
                                        defaultValue={filters[name] || 'all'}
                                    >
                                        <SelectTrigger
                                            id={name}
                                            aria-invalid={!!errors[name]}
                                            aria-describedby={`${name}-error`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All
                                            </SelectItem>
                                            {Object.entries(choices).map(
                                                ([value, text]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {text}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        id={`${name}-error`}
                                        message={errors[name]}
                                    />
                                </div>
                            ))}
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={processing}
                            >
                                <span className="size-4">
                                    {processing && <Spinner />}
                                </span>
                                Apply filters
                            </Button>
                            <Button asChild variant="ghost">
                                <Link href={index()}>Clear filters</Link>
                            </Button>
                        </>
                    )}
                </Form>
                <p className="text-sm text-muted-foreground">
                    Dates use {timezone}. Documents can overlap financially;
                    amounts are not summed.
                </p>
                {documents.data.length === 0 ? (
                    <EmptyState
                        message="No issued documents match these filters."
                        action={
                            <Link
                                href={index()}
                                className="text-primary underline underline-offset-4"
                            >
                                Show all issue dates and document types
                            </Link>
                        }
                    />
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Number</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Issued</TableHead>
                                    <TableHead>Guest / payer</TableHead>
                                    <TableHead numeric>
                                        Amount / currency
                                    </TableHead>
                                    <TableHead>ZRA status</TableHead>
                                    <TableHead>Issue-time mode</TableHead>
                                    <TableHead>Correction</TableHead>
                                    <TableHead>Documents</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.map((document) => (
                                    <TableRow
                                        key={`${document.type}-${document.id}`}
                                    >
                                        <TableCell className="whitespace-nowrap">
                                            <Identifier
                                                value={document.number}
                                                copyable
                                            />
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {types[document.type]}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            <time dateTime={document.issuedAt}>
                                                {formatDate(
                                                    document.issuedAt,
                                                    timezone,
                                                )}{' '}
                                                {formatTime(
                                                    document.issuedAt,
                                                    timezone,
                                                )}
                                            </time>
                                        </TableCell>
                                        <TableCell>
                                            {document.guest ?? 'Unavailable'}
                                        </TableCell>
                                        <TableCell numeric>
                                            {document.amount === null ||
                                            document.currency === null
                                                ? 'Unavailable'
                                                : formatMoney(
                                                      document.amount,
                                                      document.currency,
                                                  )}
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    document.zraStatus ===
                                                    'live'
                                                        ? 'success'
                                                        : document.zraStatus ===
                                                            'sandbox'
                                                          ? 'warning'
                                                          : 'unavailable'
                                                }
                                            >
                                                {statuses[document.zraStatus]}
                                            </StatusChip>
                                        </TableCell>
                                        <TableCell>
                                            <Tag>
                                                {document.mode === 'sandbox'
                                                    ? 'TEST, NOT FISCAL'
                                                    : document.mode === 'live'
                                                      ? 'Live'
                                                      : 'Not applicable'}
                                            </Tag>
                                        </TableCell>
                                        <TableCell>
                                            <Tag>None</Tag>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            <div className="flex flex-col gap-2">
                                                {document.localUrl ? (
                                                    <Link
                                                        href={document.localUrl}
                                                        className="text-primary underline underline-offset-4"
                                                    >
                                                        Open document
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Local document
                                                        unavailable
                                                    </span>
                                                )}
                                                {document.viewerUrl && (
                                                    <a
                                                        href={
                                                            document.viewerUrl
                                                        }
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary underline underline-offset-4"
                                                    >
                                                        ZRA viewer (new tab)
                                                    </a>
                                                )}
                                                {document.type !==
                                                    'non_fiscal' &&
                                                    !document.viewerUrl && (
                                                        <span className="text-muted-foreground">
                                                            ZRA viewer
                                                            unavailable
                                                        </span>
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={documents} />
                    </div>
                )}
            </div>
        </>
    );
}
