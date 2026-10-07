import { Head } from '@inertiajs/react';
import { formatDate } from '@/features/platform/format';
import { index } from '@/routes/platform/organizations';
import type { PlatformOrganization } from '@/types';

type Props = {
    organizations: PlatformOrganization[];
};

export default function PlatformOrganizations({ organizations }: Props) {
    return (
        <>
            <Head title="Organizations" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Organizations
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Every organization on the platform, for provisioning and
                        support. Guest records and other data inside an
                        organization are not shown here.
                    </p>
                </header>

                {organizations.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No organizations yet. They appear here once an owner
                        completes setup.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-card">
                        <table className="w-full text-sm">
                            <thead className="border-b text-left text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 font-medium"
                                    >
                                        Organization
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 font-medium"
                                    >
                                        Owner
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 text-right font-medium"
                                    >
                                        Properties
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 font-medium"
                                    >
                                        Created
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {organizations.map((organization) => (
                                    <tr
                                        key={organization.id}
                                        className="align-top hover:bg-background"
                                    >
                                        <td className="px-4 py-2.5">
                                            <div className="font-medium">
                                                {organization.name}
                                            </div>
                                            <div className="font-mono text-xs text-muted-foreground">
                                                {organization.id}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {organization.owners.length ===
                                            0 ? (
                                                <span className="text-muted-foreground">
                                                    No owner
                                                </span>
                                            ) : (
                                                organization.owners.map(
                                                    (owner) => (
                                                        <div key={owner.email}>
                                                            <div>
                                                                {owner.name}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {owner.email}
                                                            </div>
                                                        </div>
                                                    ),
                                                )
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums">
                                            {organization.propertyCount}
                                        </td>
                                        <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                            {formatDate(organization.createdAt)}
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

PlatformOrganizations.layout = {
    breadcrumbs: [
        {
            title: 'Organizations',
            href: index(),
        },
    ],
};
