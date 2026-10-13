import { Form } from '@inertiajs/react';
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

/**
 * Check out an in-house stay. Shows each currency's balance; while any isn't
 * zero, only someone allowed to override may proceed, with a reason.
 */
export function CheckOutDialog({
    number,
    balances,
    canOverride,
}: {
    number: string;
    balances: { currency: string; balance: number }[];
    canOverride: boolean;
}) {
    const open = balances.filter((total) => total.balance !== 0);

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button>Check out</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Check out</DialogTitle>
                    <DialogDescription>
                        {open.length === 0
                            ? 'Every folio is settled. The room is marked dirty for housekeeping.'
                            : canOverride
                              ? 'A balance is still open. Checking out leaves it as an open balance after departure for finance to settle.'
                              : 'Settle every folio balance before check-out, or ask a manager to override.'}
                    </DialogDescription>
                </DialogHeader>
                <dl className="grid grid-cols-2 gap-2">
                    {balances.map((total) => (
                        <div key={total.currency} className="contents">
                            <dt className="text-muted-foreground">
                                Balance {total.currency}
                            </dt>
                            <dd className="text-right tabular-nums">
                                {formatMoney(total.balance, total.currency)}
                            </dd>
                        </div>
                    ))}
                </dl>
                <Form {...checkOut.form(number)} className="space-y-4">
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            {open.length > 0 && canOverride && (
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
                                {(open.length === 0 || canOverride) && (
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
