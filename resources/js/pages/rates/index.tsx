import { EmptyState } from '@/components/ui/empty-state';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
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
import { formatMoney } from '@/lib/utils';
import { create, deactivate, edit, prices } from '@/routes/rate-plans';
import { Spinner } from '@/components/ui/spinner';

type Plan = {
    id: string;
    name: string;
    code: string;
    currency: string;
    active: boolean;
    roomTypes: { id: string; name: string }[];
    base: {
        id: string;
        name: string;
        adjustmentKind: 'percent' | 'amount';
        adjustment: number;
    } | null;
};
const derivation = (plan: Plan) => {
    if (!plan.base) {
        return 'Own prices';
    }
    const size = Math.abs(plan.base.adjustment);
    const amount =
        plan.base.adjustmentKind === 'percent'
            ? `${size}%`
            : formatMoney(size, plan.currency);
    return `${plan.base.name} ${plan.base.adjustment < 0 ? '−' : '+'} ${amount}`;
};
function DeactivatePlan({ plan }: { plan: Plan }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Deactivate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Deactivate {plan.name}?</DialogTitle>
                <DialogDescription>
                    It is no longer sold. The plan and its prices are kept.
                    Plans derived from it must be deactivated first.
                </DialogDescription>
                <Form
                    {...deactivate.form(plan.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors.plan} />
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
                                    Deactivate plan
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function RatePlans({
    ratePlans,
    canManage,
}: {
    ratePlans: Plan[];
    canManage: boolean;
}) {
    return (
        <>
            <Head title="Rate plans" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Rate plans
                    </h1>
                    {canManage && (
                        <Button asChild>
                            <Link href={create()}>Add rate plan</Link>
                        </Button>
                    )}
                </header>
                {ratePlans.length === 0 ? (
                    <EmptyState message="No rate plans yet. Add a rate plan to set prices." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Currency</TableHead>
                                <TableHead>Room types</TableHead>
                                <TableHead>Prices</TableHead>
                                <TableHead>
                                    <span className="sr-only">Status</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ratePlans.map((plan) => (
                                <TableRow key={plan.id}>
                                    <TableCell>
                                        <Identifier value={plan.code} />
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={prices(plan.id)}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {plan.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{plan.currency}</TableCell>
                                    <TableCell>
                                        {plan.roomTypes
                                            .map((room) => room.name)
                                            .join(', ')}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {derivation(plan)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {canManage && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(plan.id)}>
                                                    Edit
                                                </Link>
                                            </Button>
                                        )}
                                        {!plan.active ? (
                                            <StatusChip tone="unavailable">
                                                Deactivated
                                            </StatusChip>
                                        ) : (
                                            canManage && (
                                                <DeactivatePlan plan={plan} />
                                            )
                                        )}
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
