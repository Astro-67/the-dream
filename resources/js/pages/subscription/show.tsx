import { Head } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
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
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';
import { show } from '@/routes/subscription';

const tones: Record<string, StatusTone> = {
    trial: 'info',
    active: 'success',
    past_due: 'warning',
    suspended: 'error',
    cancelled: 'unavailable',
};

type Props = {
    subscription: {
        status: string;
        statusLabel: string;
        trialEndsAt: string | null;
        currentPeriodEndsAt: string | null;
    };
    plan: {
        name: string;
        propertyLimit: number;
        roomLimit: number;
        price: number;
        currency: string;
        billingPeriod: string;
    };
    usage: { properties: number; rooms: number };
    history: {
        id: string;
        from: string | null;
        to: string;
        reason: string;
        actor: string | null;
        at: string | null;
    }[];
};

function Usage({
    label,
    used,
    limit,
}: {
    label: string;
    used: number;
    limit: number;
}) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="text-2xl font-semibold tabular-nums">
                {used}{' '}
                <span className="text-base text-muted-foreground">
                    of {limit}
                </span>
            </div>
            {used >= limit && (
                <StatusChip tone="warning">Limit reached</StatusChip>
            )}
        </div>
    );
}

export default function SubscriptionShow({
    subscription,
    plan,
    usage,
    history,
}: Props) {
    return (
        <>
            <Head title="Subscription" />
            <div className="flex max-w-[960px] flex-col gap-8 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Subscription
                    </h1>
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <StatusChip tone={tones[subscription.status] ?? 'info'}>
                            {subscription.statusLabel}
                        </StatusChip>
                        <span>
                            {plan.name} ·{' '}
                            <span className="tabular-nums">
                                {formatMoney(plan.price, plan.currency)}
                            </span>{' '}
                            {plan.billingPeriod.toLowerCase()}
                        </span>
                        {subscription.status === 'trial' &&
                            subscription.trialEndsAt && (
                                <span className="text-muted-foreground">
                                    Trial ends{' '}
                                    {formatDate(subscription.trialEndsAt)}
                                </span>
                            )}
                        {subscription.status !== 'trial' &&
                            subscription.currentPeriodEndsAt && (
                                <span className="text-muted-foreground">
                                    Current period ends{' '}
                                    {formatDate(
                                        subscription.currentPeriodEndsAt,
                                    )}
                                </span>
                            )}
                    </div>
                    {subscription.status === 'suspended' && (
                        <p className="text-sm text-status-error">
                            New reservations, rooms and properties are blocked
                            until the open invoice is paid. Guests can still
                            check out, payments can still be taken and your data
                            can still be exported.
                        </p>
                    )}
                </header>

                <section aria-labelledby="usage" className="space-y-3">
                    <h2 id="usage" className="text-lg font-semibold">
                        Usage
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Usage
                            label="Properties"
                            used={usage.properties}
                            limit={plan.propertyLimit}
                        />
                        <Usage
                            label="Rooms"
                            used={usage.rooms}
                            limit={plan.roomLimit}
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Demo and archived properties don't count.
                    </p>
                </section>

                <section aria-labelledby="history" className="space-y-3">
                    <h2 id="history" className="text-lg font-semibold">
                        History
                    </h2>
                    {history.length === 0 ? (
                        <EmptyState message="No changes yet." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Change</TableHead>
                                    <TableHead>Reason</TableHead>
                                    <TableHead>By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {history.map((change) => (
                                    <TableRow key={change.id}>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDate(change.at)}
                                        </TableCell>
                                        <TableCell>
                                            {change.from
                                                ? `${change.from} → `
                                                : ''}
                                            {change.to}
                                        </TableCell>
                                        <TableCell>{change.reason}</TableCell>
                                        <TableCell>
                                            {change.actor ?? 'Thedream'}
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

SubscriptionShow.layout = {
    breadcrumbs: [{ title: 'Subscription', href: show() }],
};
