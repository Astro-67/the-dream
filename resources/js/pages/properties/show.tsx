import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/features/platform/format';
import { edit, index, show } from '@/routes/properties';
import {
    ArrowLeft,
    Building2,
    MapPin,
    Palette,
    ReceiptText,
    Settings2,
} from 'lucide-react';

type Props = {
    property: {
        id: string;
        name: string;
        propertyType: string;
        address_line: string;
        city: string;
        region: string;
        postal_code: string | null;
        phone: string;
        email: string;
        timezone: string;
        currency: string;
        jurisdictionLabel: string;
        businessDate: string;
        closeTime: string;
        receipt_prefix: string;
        star_rating: number | null;
        tin: string | null;
        vrn: string | null;
        z_number: string | null;
        zra_hotel_code?: string | null;
        status: 'setup' | 'live';
        demo: boolean;
        archivedAt: string | null;
    };
    branding: { logo_url: string | null; accent_colour: string | null };
    canUpdate: boolean;
};

export default function ShowProperty({ property, branding, canUpdate }: Props) {
    const sections = [
        {
            title: 'Contact and location',
            icon: MapPin,
            fields: [
                ['Property type', property.propertyType],
                ['Address', property.address_line],
                ['City', property.city],
                ['Region', property.region],
                ['Postal code', property.postal_code],
                ['Phone', property.phone],
                ['Email', property.email],
            ],
        },
        {
            title: 'Local settings',
            icon: Settings2,
            fields: [
                ['Jurisdiction profile', property.jurisdictionLabel],
                ['Currency', property.currency],
                ['Timezone', property.timezone.replaceAll('_', ' ')],
                ['Business date', formatDate(property.businessDate, 'UTC')],
                ['Night audit close time', property.closeTime],
                ['Receipt prefix', property.receipt_prefix],
            ],
        },
        {
            title: 'Tax registration',
            icon: ReceiptText,
            fields: [
                ['Star rating', property.star_rating ?? 'Not yet classified'],
                ['TIN', property.tin],
                ['VRN', property.vrn],
                ['Z-number', property.z_number],
                ...(property.zra_hotel_code !== undefined
                    ? [['ZRA hotel code', property.zra_hotel_code]]
                    : []),
            ],
        },
    ];

    return (
        <>
            <Head title={property.name} />
            <div className="flex min-w-0 flex-col gap-5 p-4 md:gap-6 md:p-6">
                <Link
                    href={index()}
                    className="inline-flex min-h-9 w-fit items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft aria-hidden className="size-4" />
                    Back to properties
                </Link>
                <header className="flex flex-wrap items-center justify-between gap-5 border border-l-4 border-border border-l-primary bg-card p-4 shadow-panel md:p-6">
                    <div className="flex min-w-0 items-center gap-4">
                        <span className="grid size-14 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <Building2 aria-hidden className="size-7" />
                        </span>
                        <div className="min-w-0 space-y-2">
                            <p className="text-xs font-semibold tracking-[0.12em] text-primary uppercase">
                                Property profile
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight wrap-anywhere md:text-3xl">
                                {property.name}
                            </h1>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                <span className="inline-flex items-center gap-1.5">
                                    <MapPin aria-hidden className="size-4" />
                                    {property.city},{' '}
                                    {property.jurisdictionLabel}
                                </span>
                                <span>{property.currency}</span>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <StatusChip
                                    tone={
                                        property.status === 'live'
                                            ? 'success'
                                            : 'warning'
                                    }
                                >
                                    {property.status === 'live'
                                        ? 'Live'
                                        : 'Setup'}
                                </StatusChip>
                                {property.demo && (
                                    <StatusChip tone="info">Demo</StatusChip>
                                )}
                                {property.archivedAt && (
                                    <StatusChip tone="unavailable">
                                        Archived
                                    </StatusChip>
                                )}
                            </div>
                        </div>
                    </div>
                    {canUpdate && (
                        <Button asChild>
                            <Link href={edit(property.id)}>Edit property</Link>
                        </Button>
                    )}
                </header>
                <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                    {sections.map(({ title, fields, icon: SectionIcon }) => (
                        <section
                            key={title}
                            className="min-w-0 overflow-hidden border border-border bg-card shadow-panel"
                        >
                            <div className="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-3 md:px-5">
                                <span className="grid size-9 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                    <SectionIcon
                                        aria-hidden
                                        className="size-4"
                                    />
                                </span>
                                <h2 className="font-semibold">{title}</h2>
                            </div>
                            <dl className="grid gap-x-6 gap-y-4 p-4 sm:grid-cols-2 md:p-5">
                                {fields.map(([label, value]) => (
                                    <div key={label} className="min-w-0">
                                        <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            {label}
                                        </dt>
                                        <dd className="mt-1 wrap-anywhere whitespace-pre-wrap text-sm font-medium">
                                            {value ?? 'Not set'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                    <section className="min-w-0 overflow-hidden border border-border bg-card shadow-panel xl:col-span-2">
                        <div className="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-3 md:px-5">
                            <span className="grid size-9 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                <Palette aria-hidden className="size-4" />
                            </span>
                            <div>
                                <h2 className="font-semibold">Branding</h2>
                                <p className="text-xs text-muted-foreground">
                                    Used on this property&apos;s receipts and
                                    reports.
                                </p>
                            </div>
                        </div>
                        <div className="grid gap-5 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(180px,0.5fr)] md:p-5">
                            <div className="flex min-h-24 items-center border border-border bg-muted/20 p-4">
                                {branding.logo_url ? (
                                    <img
                                        src={branding.logo_url}
                                        alt={`Logo of ${property.name}`}
                                        className="max-h-24 max-w-full object-contain object-left"
                                    />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No logo uploaded. Reports use the
                                        property name.
                                    </p>
                                )}
                            </div>
                            <dl className="grid content-center gap-1">
                                <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Accent colour
                                </dt>
                                <dd>
                                    {branding.accent_colour ? (
                                        <Identifier
                                            value={branding.accent_colour}
                                        />
                                    ) : (
                                        <span className="text-sm">Not set</span>
                                    )}
                                </dd>
                            </dl>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

ShowProperty.layout = ({ property }: Props) => ({
    breadcrumbs: [
        { title: 'Properties', href: index() },
        { title: property.name, href: show(property.id) },
    ],
});
