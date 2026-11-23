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
import { useState } from 'react';
import {
    AlertCircle,
    Archive,
    Building2,
    FlaskConical,
    Plus,
} from 'lucide-react';
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
import {
    archive,
    create,
    destroy,
    edit,
    index,
    restore,
    show,
} from '@/routes/properties';
import { store as storeDemo } from '@/routes/properties/demo';
import { formatDate } from '@/features/platform/format';
import type { Currency, JurisdictionProfile } from '@/types';
import { Spinner } from '@/components/ui/spinner';
import InputError from '@/components/input-error';

type PropertyRow = {
    id: string;
    name: string;
    city: string;
    timezone: string;
    currency: Currency;
    jurisdictionProfile: JurisdictionProfile;
    jurisdictionLabel: string;
    demo: boolean;
    goLiveRequested: boolean;
    goLiveDeclinedReason: string | null;
    can: { update: boolean; archive: boolean; delete: boolean };
};

type ArchivedPropertyRow = {
    id: string;
    name: string;
    city: string;
    timezone: string;
    jurisdictionProfile: JurisdictionProfile;
    jurisdictionLabel: string;
    archivedAt: string;
    demo: boolean;
    can: { restore: boolean };
};

function ArchiveProperty({ property }: { property: PropertyRow }) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
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
                    onSuccess={() => setOpen(false)}
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

function DeleteDemoProperty({ property }: { property: PropertyRow }) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Delete
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Delete {property.name}?</DialogTitle>
                <DialogDescription>
                    The demo property and all its sample rooms, rates and
                    reservations are removed for good. Your other properties are
                    not touched.
                </DialogDescription>
                <Form
                    {...destroy.form(property.id)}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent propertyId={property.id} />
                            {(errors.delete || errors._property) && (
                                <p
                                    role="alert"
                                    className="mb-4 flex gap-2 text-[13px] text-status-error"
                                >
                                    <AlertCircle
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    {errors.delete ?? errors._property}
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
                                    Delete demo property
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function CreateDemoProperty() {
    return (
        <Form {...storeDemo.form()}>
            {({ processing }) => (
                <>
                    <PropertyIntent />
                    <Button
                        variant="secondary"
                        disabled={processing}
                        type="submit"
                        data-test="create-demo-property"
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <FlaskConical aria-hidden />
                        )}
                        Try a demo property
                    </Button>
                </>
            )}
        </Form>
    );
}

function DemoChip() {
    return (
        <StatusChip tone="info" className="ml-2">
            Demo
        </StatusChip>
    );
}

function RestoreProperty({ property }: { property: ArchivedPropertyRow }) {
    return (
        <Form {...restore.form(property.id)} options={{ preserveScroll: true }}>
            {({ processing, errors }) => (
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
                    <InputError
                        message={
                            errors.subscription ??
                            errors.property ??
                            errors.room ??
                            errors._property
                        }
                        role="alert"
                    />
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
            <div className="flex min-w-0 flex-col gap-6 p-4 md:gap-7 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:p-5">
                    <div className="flex min-w-0 items-center gap-4">
                        <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <Building2 aria-hidden className="size-6" />
                        </span>
                        <div className="min-w-0 space-y-1">
                            <p className="text-xs font-semibold tracking-[0.12em] text-primary uppercase">
                                Organization setup
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Properties
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Manage each hotel&apos;s location, currency and
                                jurisdiction settings.
                            </p>
                        </div>
                    </div>
                    {canCreate && (
                        <div className="flex flex-wrap gap-2">
                            <CreateDemoProperty />
                            <Button asChild>
                                <Link href={create()} data-test="add-property">
                                    <Plus aria-hidden />
                                    Add property
                                </Link>
                            </Button>
                        </div>
                    )}
                </header>

                {properties.length === 0 ? (
                    <section className="grid min-h-52 place-items-center border border-dashed border-border bg-card p-6 text-center">
                        <div className="grid justify-items-center gap-3">
                            <span className="grid size-12 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                <Building2 aria-hidden className="size-6" />
                            </span>
                            <div className="space-y-1">
                                <h2 className="font-semibold">
                                    No properties yet
                                </h2>
                                <p className="max-w-md text-sm text-muted-foreground">
                                    Add a property to set up its business
                                    location, local settings and rooms.
                                </p>
                            </div>
                            {canCreate && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus aria-hidden />
                                        Add a property
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </section>
                ) : (
                    <section className="min-w-0 overflow-hidden border border-border bg-card shadow-panel">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/30 px-4 py-3 md:px-5">
                            <div>
                                <h2 className="font-semibold">
                                    Active properties
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Property access and local configuration
                                </p>
                            </div>
                            <Tag>
                                {properties.length}{' '}
                                {properties.length === 1
                                    ? 'property'
                                    : 'properties'}
                            </Tag>
                        </div>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>City</TableHead>
                                        <TableHead>Jurisdiction</TableHead>
                                        <TableHead>Currency</TableHead>
                                        <TableHead>Timezone</TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {properties.map((property) => (
                                        <TableRow key={property.id}>
                                            <TableCell className="min-w-56 font-medium">
                                                <div className="flex items-start gap-2">
                                                    <Building2
                                                        aria-hidden
                                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                                    />
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={show(
                                                                property.id,
                                                            )}
                                                            className="text-primary underline-offset-4 hover:underline"
                                                        >
                                                            {property.name}
                                                        </Link>
                                                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                            {property.demo && (
                                                                <DemoChip />
                                                            )}
                                                            {property.goLiveRequested && (
                                                                <StatusChip tone="warning">
                                                                    Go-live
                                                                    requested,
                                                                    waiting for
                                                                    Karafuu
                                                                </StatusChip>
                                                            )}
                                                            {property.id ===
                                                                tenant.property
                                                                    ?.id && (
                                                                <Tag active>
                                                                    Current
                                                                </Tag>
                                                            )}
                                                        </div>
                                                        {property.goLiveDeclinedReason && (
                                                            <div className="mt-2 space-y-1 whitespace-normal">
                                                                <StatusChip tone="error">
                                                                    Go-live
                                                                    request
                                                                    declined
                                                                </StatusChip>
                                                                <p className="max-w-sm text-sm break-words whitespace-pre-wrap text-muted-foreground">
                                                                    {
                                                                        property.goLiveDeclinedReason
                                                                    }
                                                                </p>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {property.city}
                                            </TableCell>
                                            <TableCell>
                                                <Tag>
                                                    {property.jurisdictionLabel}
                                                </Tag>
                                            </TableCell>
                                            <TableCell className="font-mono">
                                                {property.currency}
                                            </TableCell>
                                            <TableCell>
                                                {property.timezone.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
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
                                                    {property.can.delete && (
                                                        <DeleteDemoProperty
                                                            property={property}
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}

                {archivedProperties.length > 0 && (
                    <section
                        aria-labelledby="archived-properties"
                        className="flex flex-col overflow-hidden border border-border bg-card shadow-panel"
                    >
                        <div className="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-3 md:px-5">
                            <span className="grid size-9 shrink-0 place-items-center border border-border bg-background text-muted-foreground">
                                <Archive aria-hidden className="size-4" />
                            </span>
                            <div className="space-y-0.5">
                                <h2
                                    id="archived-properties"
                                    className="font-semibold"
                                >
                                    Archived properties
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Hidden from daily work; records and audit
                                    history remain available.
                                </p>
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>City</TableHead>
                                        <TableHead>Jurisdiction</TableHead>
                                        <TableHead>Archived on</TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {archivedProperties.map((property) => (
                                        <TableRow key={property.id}>
                                            <TableCell className="font-medium">
                                                {property.name}
                                                {property.demo && <DemoChip />}
                                                <ArchivedChip />
                                            </TableCell>
                                            <TableCell>
                                                {property.city}
                                            </TableCell>
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
