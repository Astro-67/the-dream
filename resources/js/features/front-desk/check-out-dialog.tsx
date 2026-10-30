import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/utils';
import { checkOut } from '@/routes/reservations';
import { Spinner } from '@/components/ui/spinner';

export type CheckoutBalance = { id: string; currency: string; balance: number };

/**
 * Check out an in-house stay. Debt always needs a permitted override and reason;
 * credit resolution follows the property's existing preference.
 */
export function CheckOutDialog({
    number,
    balances,
    canOverride,
}: {
    number: string;
    balances: CheckoutBalance[];
    canOverride: boolean;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const open = balances.filter((total) => total.balance !== 0);
    const { blockCheckoutIfUnpaid = true } = usePage<{
        blockCheckoutIfUnpaid?: boolean;
    }>().props;
    const hasDebt = open.some((total) => total.balance > 0);
    const blocked = hasDebt || (open.length > 0 && blockCheckoutIfUnpaid);
    if (blocked && !canOverride) {
        return (
            <div className="grid max-w-sm gap-2">
                <Button
                    disabled
                    aria-describedby={`check-out-blocked-${number}`}
                >
                    Check out
                </Button>
                <p
                    id={`check-out-blocked-${number}`}
                    className="text-sm text-muted-foreground"
                >
                    {hasDebt
                        ? 'Check-out is not allowed. All outstanding balances must be paid before check-out.'
                        : 'Settle every folio balance before check-out.'}
                </p>
            </div>
        );
    }

    return (
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
            <DialogTrigger asChild>
                <Button>Check out</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Check out</DialogTitle>
                    <DialogDescription>
                        {open.length === 0
                            ? 'Every folio is settled. The room is marked dirty for housekeeping.'
                            : 'A balance is still open. Checking out leaves it as an open balance after departure for finance to settle.'}
                    </DialogDescription>
                </DialogHeader>
                <dl className="grid grid-cols-2 gap-2">
                    {balances.map((total, index) => (
                        <div key={total.id} className="contents">
                            <dt className="text-muted-foreground">
                                Folio {index + 1} balance {total.currency}
                            </dt>
                            <dd className="text-right tabular-nums">
                                {formatMoney(total.balance, total.currency)}
                            </dd>
                        </div>
                    ))}
                </dl>
                <Form
                    {...checkOut.form(number)}
                    onSuccess={() => setDialogOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            {blocked && canOverride && (
                                <div className="space-y-2">
                                    <Label htmlFor="check-out-override-reason">
                                        Reason for leaving a balance open
                                    </Label>
                                    <Input
                                        id="check-out-override-reason"
                                        name="override_reason"
                                        required
                                        maxLength={255}
                                        aria-invalid={Boolean(
                                            errors.override_reason,
                                        )}
                                    />
                                    <InputError
                                        message={errors.override_reason}
                                    />
                                </div>
                            )}
                            <InputError message={errors.balance} />
                            <InputError message={errors.reservation} />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <span className="size-4">
                                            {processing && <Spinner />}
                                        </span>
                                        Not now
                                    </Button>
                                </DialogClose>
                                {(!blocked || canOverride) && (
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {open.length === 0
                                            ? 'Check out'
                                            : 'Check out with open balance'}
                                    </Button>
                                )}
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
