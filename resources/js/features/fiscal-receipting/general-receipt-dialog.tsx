import { Form } from '@inertiajs/react';
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
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { postingCategories } from '@/features/billing/folio-panel';
import { formatMoney } from '@/lib/utils';

export type GeneralReceiptPreview = {
    customer: string;
    items: {
        id: string;
        description: string | null;
        category: string;
        quantity: number;
        unit_price: number;
        discount: number;
        amount: number;
        currency: string;
    }[];
    totals: { currency: string; amount: number }[];
    url: string;
};

/** Issue ZRA receipt: shows exactly what goes to ZRA before sending, because a ZRA receipt cannot be cancelled. */
export function GeneralReceiptDialog({
    preview,
    closeAccount = false,
    fiscalEnabled = true,
}: {
    preview: GeneralReceiptPreview | null;
    closeAccount?: boolean;
    fiscalEnabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    if (preview === null) return null;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className="min-h-11">
                    {closeAccount
                        ? fiscalEnabled
                            ? 'Close and issue receipt'
                            : 'Close account'
                        : 'Issue ZRA receipt'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {closeAccount
                            ? 'Close account for'
                            : 'Issue a ZRA receipt to'}{' '}
                        {preview.customer}?
                    </DialogTitle>
                    <DialogDescription>
                        {fiscalEnabled
                            ? 'This sends a receipt to ZRA. ZRA receipts cannot be cancelled.'
                            : 'This account will close without a ZRA filing for this property.'}
                        {closeAccount &&
                            ' Every currency balance must be zero.'}
                    </DialogDescription>
                </DialogHeader>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Item</TableHead>
                            <TableHead numeric>Qty</TableHead>
                            <TableHead numeric>Price</TableHead>
                            <TableHead numeric>Discount</TableHead>
                            <TableHead numeric>Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {preview.items.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell>
                                    {item.description ??
                                        postingCategories[item.category] ??
                                        item.category}
                                </TableCell>
                                <TableCell numeric>{item.quantity}</TableCell>
                                <TableCell numeric>
                                    {formatMoney(
                                        item.unit_price,
                                        item.currency,
                                    )}
                                </TableCell>
                                <TableCell numeric>
                                    {formatMoney(item.discount, item.currency)}
                                </TableCell>
                                <TableCell numeric>
                                    {formatMoney(item.amount, item.currency)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
                <dl className="space-y-1">
                    {preview.totals.map((total) => (
                        <div
                            key={total.currency}
                            className="flex justify-between gap-4 font-medium"
                        >
                            <dt>Total {total.currency}</dt>
                            <dd className="tabular-nums">
                                {formatMoney(total.amount, total.currency)}
                            </dd>
                        </div>
                    ))}
                </dl>
                <Form
                    action={preview.url}
                    method="post"
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <InputError message={errors.general_receipt} />
                            <InputError message={errors.balance} />
                            <InputError message={errors.account} />
                            <DialogFooter className="mt-4">
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {closeAccount
                                        ? fiscalEnabled
                                            ? 'Close and send to ZRA'
                                            : 'Close account'
                                        : 'Send to ZRA'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
