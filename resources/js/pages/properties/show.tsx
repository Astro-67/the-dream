import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/features/platform/format';
import { edit, index, show } from '@/routes/properties';

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
            <div className="flex max-w-[720px] min-w-0 flex-col gap-8 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 flex-1 space-y-3">
                        <h1 className="text-2xl font-semibold wrap-anywhere">
                            {property.name}
                        </h1>
                        <div className="flex flex-wrap gap-2">
                            <StatusChip
                                tone={
                                    property.status === 'live'
                                        ? 'success'
                                        : 'warning'
                                }
                            >
                                {property.status === 'live' ? 'Live' : 'Setup'}
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
                    {canUpdate && (
                        <Button asChild>
                            <Link href={edit(property.id)}>Edit property</Link>
                        </Button>
                    )}
                </header>
                {sections.map(({ title, fields }) => (
                    <section key={title} className="space-y-4 border-t pt-6">
                        <h2 className="text-lg font-semibold">{title}</h2>
                        <dl className="grid gap-4 sm:grid-cols-2">
                            {fields.map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-sm text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="wrap-anywhere whitespace-pre-wrap">
                                        {value ?? 'Not set'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                ))}
                <section className="space-y-4 border-t pt-6">
                    <div className="space-y-1">
                        <h2 className="text-lg font-semibold">Branding</h2>
                        <p className="text-sm text-muted-foreground">
                            Used on this property&apos;s fiscal check-out
                            reports.
                        </p>
                    </div>
                    {branding.logo_url ? (
                        <img
                            src={branding.logo_url}
                            alt={`Logo of ${property.name}`}
                            className="max-h-24 max-w-full object-contain object-left"
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No logo uploaded. Reports use the property name.
                        </p>
                    )}
                    <dl>
                        <dt className="text-sm text-muted-foreground">
                            Accent colour
                        </dt>
                        <dd>
                            {branding.accent_colour ? (
                                <Identifier value={branding.accent_colour} />
                            ) : (
                                'Not set'
                            )}
                        </dd>
                    </dl>
                </section>
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
