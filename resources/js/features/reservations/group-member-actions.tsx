import { CheckInForm } from '@/features/front-desk/check-in-form';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { PaymentForm } from '@/features/billing/payment-form';
import { dueLine, type FolioTotal } from '@/features/billing/folio-summary';
import {
    CheckOutDialog,
    type CheckoutBalance,
} from '@/features/front-desk/check-out-dialog';

export type GroupMember = {
    reservation_number: string;
    status: string;
    arrival: string;
    departure: string;
    can: { checkIn: boolean; checkOut: boolean; overrideBalance: boolean };
    checkoutBalances: CheckoutBalance[];
    channelCollectedAmount: number | null;
    paymentFolios: { id: string; currency: string; total: FolioTotal }[];
};

export function GroupMemberActions({
    member,
    acceptedPaymentMethods,
}: {
    member: GroupMember;
    acceptedPaymentMethods: string[];
}) {
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [folioId, setFolioId] = useState<string | null>(null);
    const folio =
        member.paymentFolios.find((item) => item.id === folioId) ??
        member.paymentFolios[0];
    const due = folio && dueLine(folio.total.due, folio.currency);

    return (
        <div className="flex flex-wrap items-start gap-2 [&_button]:min-h-11">
            {member.can.checkIn && (
                <CheckInForm number={member.reservation_number} />
            )}
            {member.can.checkOut && (
                <CheckOutDialog
                    number={member.reservation_number}
                    balances={member.checkoutBalances}
                    canOverride={member.can.overrideBalance}
                />
            )}
            {folio && (
                <Dialog open={paymentOpen} onOpenChange={setPaymentOpen}>
                    <DialogTrigger asChild>
                        <Button variant="outline">Pay</Button>
                    </DialogTrigger>
                    <DialogContent className="max-h-[90dvh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>
                                Pay reservation{' '}
                                <Identifier value={member.reservation_number} />
                            </DialogTitle>
                            <DialogDescription>
                                {due?.label}: {due?.amount}
                                {folio.total.estimate &&
                                    ' (includes estimated nights before added taxes)'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2">
                            <Label
                                htmlFor={`group-folio-${member.reservation_number}`}
                            >
                                Folio
                            </Label>
                            <NativeSelect
                                id={`group-folio-${member.reservation_number}`}
                                value={folio.id}
                                onChange={(event) =>
                                    setFolioId(event.target.value)
                                }
                            >
                                {member.paymentFolios.map((item, index) => (
                                    <option key={item.id} value={item.id}>
                                        Folio {index + 1} - {item.currency}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <PaymentForm
                            key={folio.id}
                            folio={folio}
                            total={folio.total}
                            channelCollectedAmount={
                                member.channelCollectedAmount
                            }
                            acceptedMethods={acceptedPaymentMethods}
                            onSuccess={() => setPaymentOpen(false)}
                        />
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}
