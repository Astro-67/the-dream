import { CheckInForm } from '@/features/front-desk/check-in-form';
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
};

export function GroupMemberActions({ member }: { member: GroupMember }) {
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
        </div>
    );
}
