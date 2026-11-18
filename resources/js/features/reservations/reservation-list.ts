import type { CheckoutBalance } from '@/features/front-desk/check-out-dialog';

export type ReservationListItem = {
    id: string;
    reservation_number: string;
    booking_group_id: string | null;
    arrival: string;
    departure: string;
    status: string;
    is_overstay: boolean;
    channel_reference: string | null;
    requires_reconciliation: boolean;
    can_check_in: boolean;
    can_check_out: boolean;
    checkout_balances: CheckoutBalance[];
    can_override_balance?: boolean;
    guest_name: string | null;
    room_number: string | null;
    balances: { currency: string; balance: number; unposted: boolean }[];
};

export function paymentState({
    balance,
    unposted,
}: ReservationListItem['balances'][number]): {
    label: string;
    tone: 'error' | 'success' | 'info' | 'warning';
} {
    if (balance > 0) return { label: 'Due', tone: 'error' };
    if (balance < 0) return { label: 'Credit', tone: 'info' };
    return unposted
        ? { label: 'Not yet charged', tone: 'warning' }
        : { label: 'Settled', tone: 'success' };
}
