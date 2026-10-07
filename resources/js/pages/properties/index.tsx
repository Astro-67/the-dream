import { Form, Head, Link, usePage } from '@inertiajs/react';
import { AlertCircle, Plus } from 'lucide-react';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { archive, create, edit, index, restore } from '@/routes/properties';
import type { Currency, JurisdictionProfile } from '@/types';

type PropertyRow = {
    id: string;
    name: string;
    city: string;
    timezone: string;
    currency: Currency;
    jurisdictionProfile: JurisdictionProfile;
    can: { update: boolean; archive: boolean };
};

type ArchivedPropertyRow = {
    id: string;
    name: string;
    city: string;
    timezone: string;
    jurisdictionProfile: JurisdictionProfile;
    archivedAt: string;
    can: { restore: boolean };
};

const jurisdictionLabels: Record<JurisdictionProfile, string> = {
    zanzibar: 'Zanzibar',
    mainland_tanzania: 'Mainland Tanzania',
};

/** "7 Oct 2026", in the property's own timezone. */
function formatDate(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone,
    }).format(new Date(iso));
}

function ArchiveProperty({ property }: { property: PropertyRow }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Archive
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Archive {property.name}?</DialogTitle>
                <DialogDescription>
                    It leaves the property switcher and day-to-day lists, and
                    nobody can work in it. Its records and audit history are
                    kept, and you can restore it at any time.
                </DialogDescription>
                <Form
                    {...archive.form(property.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent propertyId={property.id} />
                            {(errors.archive || errors._property) && (
                                <p
                                    role="alert"
                                    className="mb-4 flex gap-2 text-[13px] text-red-700 dark:text-red-400"
                                >
                                    <AlertCircle
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    {errors.archive ?? errors._property}
                                </p>
                            )}
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Keep it</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                    type="submit"
                                >
                                    Archive property
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function RestoreProperty({ property }: { property: ArchivedPropertyRow }) {
    return (
        <Form {...restore.form(property.id)} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <>
                    <PropertyIntent />
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={processing}
                        type="submit"
                        aria-label={`Restore ${property.name}`}
                    >
                        Restore
                    </Button>
                </>
            )}
        </Form>
    );
}

function ArchivedChip() {
    return (
        <span className="ml-2 inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-400/15 dark:text-slate-400">
            <span
                aria-hidden
                className="size-1.5 rounded-full bg-slate-500 dark:bg-slate-400"
            />
            Archived
        </span>
    );
}

export default function PropertiesIndex({
    properties,
    archivedProperties,
    canCreate,
}: {
    properties: PropertyRow[];
    archivedProperties: ArchivedPropertyRow[];
    canCreate: boolean;
}) {
    const { tenant } = usePage().props;

    return (
        <>
            <Head title="Properties" />
            <div className="flex flex-col gap-6 p-4 md:gap-8 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Properties
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Each property keeps its own timezone, currency and
                            jurisdiction profile.
                        </p>
                    </div>
                    {canCreate && (
                        <Button asChild>
                            <Link href={create()} data-test="add-property">
                                <Plus aria-hidden />
                                Add property
                            </Link>
                        </Button>
                    )}
                </header>

                {properties.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No properties you can work in.{' '}
                        {canCreate && (
                            <Link
                                href={create()}
                                className="font-medium text-primary underline-offset-4 hover:underline"
                            >
                                Add a property
                            </Link>
                        )}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 border-b bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="h-10 px-4 font-medium">
                                        Name
                                    </th>
                                    <th className="h-10 px-4 font-medium">
                                        City
                                    </th>
                                    <th className="h-10 px-4 font-medium">
                                        Jurisdiction
                                    </th>
                                    <th className="h-10 px-4 font-medium">
                                        Currency
                                    </th>
                                    <th className="h-10 px-4 font-medium">
                                        Timezone
                                    </th>
                                    <th className="h-10 px-4">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {properties.map((property) => (
                                    <tr
                                        key={property.id}
                                        className="h-10 hover:bg-muted/40"
                                    >
                                        <td className="px-4 py-2 font-medium">
                                            {property.name}
                                            {property.id ===
                                                tenant.property?.id && (
                                                <span className="ml-2 rounded-sm bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary">
                                                    Current
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {property.city}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className="rounded-sm bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                                {
                                                    jurisdictionLabels[
                                                        property
                                                            .jurisdictionProfile
                                                    ]
                                                }
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 font-mono">
                                            {property.currency}
                                        </td>
                                        <td className="px-4 py-2">
                                            {property.timezone.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            <div className="flex justify-end gap-2">
                                                {property.can.update && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={edit(
                                                                property.id,
                                                            )}
                                                            aria-label={`Edit ${property.name}`}
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                )}
                                                {property.can.archive && (
                                                    <ArchiveProperty
                                                        property={property}
                                                    />
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {archivedProperties.length > 0 && (
                    <section
                        aria-labelledby="archived-properties"
                        className="flex flex-col gap-3"
                    >
                        <div className="space-y-1">
                            <h2
                                id="archived-properties"
                                className="text-lg font-semibold tracking-tight"
                            >
                                Archived
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Out of the switcher and day-to-day lists. Their
                                records and audit history are kept.
                            </p>
                        </div>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 border-b bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th className="h-10 px-4 font-medium">
                                            Name
                                        </th>
                                        <th className="h-10 px-4 font-medium">
                                            City
                                        </th>
                                        <th className="h-10 px-4 font-medium">
                                            Jurisdiction
                                        </th>
                                        <th className="h-10 px-4 font-medium">
                                            Archived on
                                        </th>
                                        <th className="h-10 px-4">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {archivedProperties.map((property) => (
                                        <tr
                                            key={property.id}
                                            className="h-10 hover:bg-muted/40"
                                        >
                                            <td className="px-4 py-2 font-medium">
                                                {property.name}
                                                <ArchivedChip />
                                            </td>
                                            <td className="px-4 py-2">
                                                {property.city}
                                            </td>
                                            <td className="px-4 py-2">
                                                <span className="rounded-sm bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                                    {
                                                        jurisdictionLabels[
                                                            property
                                                                .jurisdictionProfile
                                                        ]
                                                    }
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 tabular-nums">
                                                {formatDate(
                                                    property.archivedAt,
                                                    property.timezone,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                <div className="flex justify-end">
                                                    {property.can.restore && (
                                                        <RestoreProperty
                                                            property={property}
                                                        />
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

PropertiesIndex.layout = {
    breadcrumbs: [{ title: 'Properties', href: index() }],
};
