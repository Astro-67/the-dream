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
                    <p className="text-muted-foreground">
                        No rate plans yet. Add a rate plan to set prices.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="p-3">Code</th>
                                    <th className="p-3">Name</th>
                                    <th className="p-3">Currency</th>
                                    <th className="p-3">Room types</th>
                                    <th className="p-3">Prices</th>
                                    <th className="p-3">
                                        <span className="sr-only">Status</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {ratePlans.map((plan) => (
                                    <tr
                                        key={plan.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 font-mono">
                                            {plan.code}
                                        </td>
                                        <td className="p-3">
                                            <Link
                                                href={prices(plan.id)}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {plan.name}
                                            </Link>
                                        </td>
                                        <td className="p-3">{plan.currency}</td>
                                        <td className="p-3">
                                            {plan.roomTypes
                                                .map((room) => room.name)
                                                .join(', ')}
                                        </td>
                                        <td className="p-3 tabular-nums">
                                            {derivation(plan)}
                                        </td>
                                        <td className="p-3 text-right">
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
                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-status-unavailable-tint px-2 py-0.5 text-xs font-medium text-status-unavailable">
                                                    <span
                                                        aria-hidden
                                                        className="size-1.5 rounded-full bg-status-unavailable"
                                                    />
                                                    Deactivated
                                                </span>
                                            ) : (
                                                canManage && (
                                                    <DeactivatePlan
                                                        plan={plan}
                                                    />
                                                )
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
