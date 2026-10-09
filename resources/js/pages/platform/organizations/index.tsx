import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
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
                    <EmptyState message="No organizations yet. They appear here once an owner completes setup." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead scope="col">Organization</TableHead>
                                <TableHead scope="col">Owner</TableHead>
                                <TableHead numeric scope="col">
                                    Properties
                                </TableHead>
                                <TableHead scope="col">Created</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {organizations.map((organization) => (
                                <TableRow
                                    key={organization.id}
                                    className="align-top"
                                >
                                    <TableCell>
                                        <div className="font-medium">
                                            {organization.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            <Identifier
                                                value={organization.id}
                                            />
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {organization.owners.length === 0 ? (
                                            <span className="text-muted-foreground">
                                                No owner
                                            </span>
                                        ) : (
                                            organization.owners.map((owner) => (
                                                <div key={owner.email}>
                                                    <div>{owner.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {owner.email}
                                                    </div>
                                                </div>
                                            ))
                                        )}
                                    </TableCell>
                                    <TableCell numeric>
                                        {organization.propertyCount}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap tabular-nums">
                                        {formatDate(organization.createdAt)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
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
