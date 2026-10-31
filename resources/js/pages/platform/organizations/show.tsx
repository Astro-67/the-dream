import { Head } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/features/platform/format';
import { index } from '@/routes/platform/organizations';
import type { PlatformOrganizationOwner } from '@/types';

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
};

export default function PlatformOrganizationShow({
    organization,
    subscription,
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
                        <EmptyState
                            title="No owners"
                            description="No owner is assigned to this organization."
                        />
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
                                            subscription.status === 'active'
                                                ? 'success'
                                                : 'info'
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
                        <EmptyState
                            title="No subscription"
                            description="This organization has no subscription yet."
                        />
                    )}
                </section>
            </div>
        </>
    );
}

PlatformOrganizationShow.layout = {
    breadcrumbs: [{ title: 'Organizations', href: index() }],
};
