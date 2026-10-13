import { Tag } from '@/components/ui/status-chip';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
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
import { formatDate } from '@/features/platform/format';
import type { Currency, JurisdictionProfile } from '@/types';
import { Spinner } from '@/components/ui/spinner';

type PropertyRow = {
    id: string;
    name: string;
    city: string;
    timezone: string;
    currency: Currency;
    jurisdictionProfile: JurisdictionProfile;
    jurisdictionLabel: string;
    can: { update: boolean; archive: boolean };
};

type ArchivedPropertyRow = {
    id: string;
    name: string;
    city: string;
    timezone: string;
    jurisdictionProfile: JurisdictionProfile;
    jurisdictionLabel: string;
    archivedAt: string;
    can: { restore: boolean };
};

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
                                    className="mb-4 flex gap-2 text-[13px] text-status-error"
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
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
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
                        <span className="size-4">
                            {processing && <Spinner />}
                        </span>
                        Restore
                    </Button>
                </>
            )}
        </Form>
    );
}

function ArchivedChip() {
    return (
        <StatusChip tone="unavailable" className="ml-2">
            Archived
        </StatusChip>
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
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>City</TableHead>
                                <TableHead>Jurisdiction</TableHead>
                                <TableHead>Currency</TableHead>
                                <TableHead>Timezone</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {properties.map((property) => (
                                <TableRow key={property.id}>
                                    <TableCell className="font-medium">
                                        {property.name}
                                        {property.id ===
                                            tenant.property?.id && (
                                            <Tag
                                                active
                                                className="ml-2 font-medium"
                                            >
                                                Current
                                            </Tag>
                                        )}
                                    </TableCell>
                                    <TableCell>{property.city}</TableCell>
                                    <TableCell>
                                        <Tag>{property.jurisdictionLabel}</Tag>
                                    </TableCell>
                                    <TableCell className="font-mono">
                                        {property.currency}
                                    </TableCell>
                                    <TableCell>
                                        {property.timezone.replaceAll('_', ' ')}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end gap-2">
                                            {property.can.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={edit(property.id)}
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
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
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

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>City</TableHead>
                                    <TableHead>Jurisdiction</TableHead>
                                    <TableHead>Archived on</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {archivedProperties.map((property) => (
                                    <TableRow key={property.id}>
                                        <TableCell className="font-medium">
                                            {property.name}
                                            <ArchivedChip />
                                        </TableCell>
                                        <TableCell>{property.city}</TableCell>
                                        <TableCell>
                                            <Tag>
                                                {property.jurisdictionLabel}
                                            </Tag>
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatDate(
                                                property.archivedAt,
                                                property.timezone,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end">
                                                {property.can.restore && (
                                                    <RestoreProperty
                                                        property={property}
                                                    />
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                )}
            </div>
        </>
    );
}

PropertiesIndex.layout = {
    breadcrumbs: [{ title: 'Properties', href: index() }],
};
