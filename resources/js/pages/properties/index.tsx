import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/properties';
import type { Currency, JurisdictionProfile } from '@/types';

type PropertyRow = {
    id: string;
    name: string;
    city: string;
    timezone: string;
    currency: Currency;
    jurisdictionProfile: JurisdictionProfile;
    canUpdate: boolean;
};

const jurisdictionLabels: Record<JurisdictionProfile, string> = {
    zanzibar: 'Zanzibar',
    mainland_tanzania: 'Mainland Tanzania',
};

export default function PropertiesIndex({
    properties,
    canCreate,
}: {
    properties: PropertyRow[];
    canCreate: boolean;
}) {
    const { tenant } = usePage().props;

    return (
        <>
            <Head title="Properties" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
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
                                        <td className="px-4 py-2 text-right">
                                            {property.canUpdate && (
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
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

PropertiesIndex.layout = {
    breadcrumbs: [{ title: 'Properties', href: index() }],
};
