import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import { StatusChip } from '@/components/ui/status-chip';
import type { StatusTone } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ApproveGoLiveForm } from '@/features/platform/approve-go-live-form';
import { formatDate } from '@/features/platform/format';
import { DeclineGoLive } from '@/features/platform/decline-go-live';
import { SaasInvoiceStatus } from '@/features/subscriptions/saas-invoice-status';
import type { SaasInvoice } from '@/features/subscriptions/saas-invoice-status';
import { formatMoney } from '@/lib/utils';
import { index } from '@/routes/platform/organizations';
import { show as showInvoice } from '@/routes/platform/invoices';
import type { Paginated, PlatformOrganizationOwner } from '@/types';

const subscriptionTones: Record<string, StatusTone> = {
    trial: 'info',
    active: 'success',
    past_due: 'warning',
    suspended: 'error',
    cancelled: 'unavailable',
};

type Props = {
    organization: {
        id: string;
        name: string;
        owners: PlatformOrganizationOwner[];
    };
    subscription: {
        plan: string;
        status: string;
        statusLabel: string;
        currentPeriodEndsAt: string | null;
    } | null;
    invoices: Paginated<
        SaasInvoice & {
            payments: {
                id: string;
                amount: number;
                method: string;
                reference: string;
                receivedOn: string;
            }[];
        }
    >;
    properties: {
        id: string;
        name: string;
        email: string;
        phone: string;
        status: string;
        statusLabel: string;
        archived: boolean;
        gates: { key: string; label: string; passed: boolean }[];
        vfmsMode: 'sandbox' | 'live';
        credentialsSaved: boolean;
    }[];
};

export default function PlatformOrganizationShow({
    organization,
    subscription,
    invoices,
    properties,
}: Props) {
    return (
        <>
            <Head title={organization.name} />
            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {organization.name}
                    </h1>
                    <Identifier value={organization.id} />
                </header>
                <section className="space-y-4" aria-labelledby="owners-heading">
                    <h2 id="owners-heading" className="text-lg font-semibold">
                        Owners and contacts
                    </h2>
                    {organization.owners.length === 0 ? (
                        <EmptyState message="No owner is assigned to this organization." />
                    ) : (
                        organization.owners.map((owner) => (
                            <div key={owner.email}>
                                <div className="font-medium">{owner.name}</div>
                                <a
                                    className="text-primary underline underline-offset-4"
                                    href={`mailto:${owner.email}`}
                                >
                                    {owner.email}
                                </a>
                            </div>
                        ))
                    )}
                </section>
                <section
                    className="space-y-4"
                    aria-labelledby="subscription-heading"
                >
                    <h2
                        id="subscription-heading"
                        className="text-lg font-semibold"
                    >
                        Subscription
                    </h2>
                    {subscription ? (
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-sm text-muted-foreground">
                                    Plan
                                </dt>
                                <dd>{subscription.plan}</dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">
                                    Status
                                </dt>
                                <dd>
                                    <StatusChip
                                        tone={
                                            subscriptionTones[
                                                subscription.status
                                            ] ?? 'info'
                                        }
                                    >
                                        {subscription.statusLabel}
                                    </StatusChip>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">
                                    Paid through
                                </dt>
                                <dd>
                                    {formatDate(
                                        subscription.currentPeriodEndsAt,
                                    ) || 'Not paid yet'}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <EmptyState message="This organization has no subscription yet." />
                    )}
                </section>
                <section
                    className="space-y-4"
                    aria-labelledby="invoices-heading"
                >
                    <h2 id="invoices-heading" className="text-lg font-semibold">
                        Invoices and payments
                    </h2>
                    {invoices.data.length === 0 ? (
                        <EmptyState message="No invoices have been issued. Issue invoices from the platform Invoices page." />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Invoice</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead numeric>Amount</TableHead>
                                        <TableHead numeric>Balance</TableHead>
                                        <TableHead>Payments</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.data.map((invoice) => (
                                        <TableRow
                                            key={invoice.id}
                                            className="align-top"
                                        >
                                            <TableCell className="whitespace-nowrap">
                                                <Link
                                                    className="text-primary underline underline-offset-4"
                                                    href={showInvoice(
                                                        invoice.id,
                                                    )}
                                                >
                                                    <Identifier
                                                        value={invoice.number}
                                                    />
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {formatDate(
                                                        invoice.periodStartsAt,
                                                    )}{' '}
                                                    –{' '}
                                                    {formatDate(
                                                        invoice.periodEndsAt,
                                                    )}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Due{' '}
                                                    {formatDate(invoice.dueAt)}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <SaasInvoiceStatus
                                                    invoice={invoice}
                                                />
                                            </TableCell>
                                            <TableCell
                                                numeric
                                                className="whitespace-nowrap"
                                            >
                                                {formatMoney(
                                                    invoice.amount,
                                                    invoice.currency,
                                                )}
                                            </TableCell>
                                            <TableCell
                                                numeric
                                                className="whitespace-nowrap"
                                            >
                                                {formatMoney(
                                                    invoice.balance,
                                                    invoice.currency,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {invoice.payments.length ===
                                                0 ? (
                                                    <span className="text-muted-foreground">
                                                        No payments recorded
                                                    </span>
                                                ) : (
                                                    <ul className="space-y-3">
                                                        {invoice.payments.map(
                                                            (payment) => (
                                                                <li
                                                                    key={
                                                                        payment.id
                                                                    }
                                                                    className="space-y-1"
                                                                >
                                                                    <div className="tabular-nums">
                                                                        {formatMoney(
                                                                            payment.amount,
                                                                            invoice.currency,
                                                                        )}
                                                                    </div>
                                                                    <div>
                                                                        {
                                                                            payment.method
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {formatDate(
                                                                            payment.receivedOn,
                                                                        )}
                                                                    </div>
                                                                    <Identifier
                                                                        value={
                                                                            payment.reference
                                                                        }
                                                                    />
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination page={invoices} />
                        </>
                    )}
                </section>
                <section
                    className="space-y-4"
                    aria-labelledby="properties-heading"
                >
                    <h2
                        id="properties-heading"
                        className="text-lg font-semibold"
                    >
                        Properties
                    </h2>
                    {properties.length === 0 ? (
                        <EmptyState message="No properties have been added to this organization." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Property</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Go-live gates</TableHead>
                                    <TableHead>VFMS</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {properties.map((property) => (
                                    <TableRow
                                        key={property.id}
                                        className="align-top"
                                    >
                                        <TableCell>
                                            <div className="font-medium">
                                                {property.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {property.email}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {property.phone}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    property.status === 'live'
                                                        ? 'success'
                                                        : property.status ===
                                                            'go_live_requested'
                                                          ? 'warning'
                                                          : 'info'
                                                }
                                            >
                                                {property.statusLabel}
                                            </StatusChip>
                                            {property.status ===
                                                'go_live_requested' &&
                                                !property.archived && (
                                                    <div>
                                                        <DeclineGoLive
                                                            property={property}
                                                        />
                                                    </div>
                                                )}
                                            {property.status ===
                                                'go_live_requested' && (
                                                <ApproveGoLiveForm
                                                    propertyId={property.id}
                                                />
                                            )}
                                            {property.archived && (
                                                <div className="mt-2">
                                                    <StatusChip tone="unavailable">
                                                        Archived
                                                    </StatusChip>
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <ul className="space-y-2">
                                                {property.gates.map((gate) => (
                                                    <li
                                                        key={gate.key}
                                                        className="flex flex-wrap items-center gap-2"
                                                    >
                                                        <StatusChip
                                                            tone={
                                                                gate.passed
                                                                    ? 'success'
                                                                    : 'error'
                                                            }
                                                        >
                                                            {gate.passed
                                                                ? 'Passed'
                                                                : 'Failed'}
                                                        </StatusChip>
                                                        <span>
                                                            {gate.label}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip
                                                tone={
                                                    property.vfmsMode === 'live'
                                                        ? 'success'
                                                        : 'info'
                                                }
                                            >
                                                {property.vfmsMode === 'live'
                                                    ? 'Live'
                                                    : 'Sandbox'}
                                            </StatusChip>
                                            <div className="mt-2 text-sm">
                                                Credentials saved:{' '}
                                                {property.credentialsSaved
                                                    ? 'Yes'
                                                    : 'No'}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </div>
        </>
    );
}

PlatformOrganizationShow.layout = {
    breadcrumbs: [{ title: 'Organizations', href: index() }],
};
