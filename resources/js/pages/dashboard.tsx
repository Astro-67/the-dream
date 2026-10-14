import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import {
    OnboardingChecklist,
    type Checklist,
} from '@/features/tenancy/onboarding-checklist';
import { useCan } from '@/hooks/use-can';
import { formatDate } from '@/lib/utils';
import { dashboard } from '@/routes';
import { create as createProperty } from '@/routes/properties';
import { create as createReservation } from '@/routes/reservations';

type Today = {
    businessDate: string;
    lists: { key: string; count: number; href: string }[];
};

const listLabels: Record<string, string> = {
    arrivals: 'Arrivals',
    departures: 'Departures',
    'in-house': 'In house',
};

export default function Dashboard({
    onboarding,
    today,
}: {
    onboarding: Checklist | null;
    today: Today | null;
}) {
    const { auth, tenant } = usePage().props;
    const can = useCan();

    if (onboarding) {
        return (
            <>
                <Head title={`Set up ${onboarding.propertyName}`} />
                <OnboardingChecklist checklist={onboarding} />
            </>
        );
    }

    if (!tenant.property) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {tenant.organization?.name ?? 'Dashboard'}
                    </h1>
                    <div className="rounded-xl border">
                        <EmptyState
                            message={
                                can('properties.manage')
                                    ? 'There is no active property to work in yet. Add one to start setting it up.'
                                    : 'You have not been given access to a property yet. Ask an owner of this organization to add you to one.'
                            }
                            action={
                                can('properties.manage') && (
                                    <Button asChild>
                                        <Link href={createProperty()}>
                                            Add property
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex max-w-[960px] flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Welcome back, {auth.user.name.split(' ')[0]}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {tenant.property.name}
                            {today &&
                                ` · Business date ${formatDate(today.businessDate)}`}
                        </p>
                    </div>
                    {can('reservations.manage') && (
                        <Button asChild>
                            <Link href={createReservation()}>
                                New reservation
                            </Link>
                        </Button>
                    )}
                </header>

                {today ? (
                    <section aria-labelledby="today" className="space-y-3">
                        <h2 id="today" className="text-lg font-semibold">
                            Today
                        </h2>
                        <ul className="grid gap-4 sm:grid-cols-3">
                            {today.lists.map((list) => (
                                <li key={list.key}>
                                    <Link
                                        href={list.href}
                                        className="flex flex-col gap-1 rounded-xl border p-4 transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <span className="text-sm text-muted-foreground">
                                            {listLabels[list.key] ?? list.key}
                                        </span>
                                        <span className="text-3xl font-semibold tabular-nums">
                                            {list.count}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : (
                    <div className="rounded-xl border">
                        <EmptyState message="Use the menu to open the parts of the system you work in." />
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
