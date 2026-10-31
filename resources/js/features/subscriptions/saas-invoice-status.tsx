import { StatusChip } from '@/components/ui/status-chip';

export type SaasInvoice = {
    id: string;
    number: string;
    status: 'open' | 'paid';
    statusLabel: string;
    overdue: boolean;
    periodStartsAt: string;
    periodEndsAt: string;
    dueAt: string;
    rooms: number | null;
    unitPrice: number | null;
    amount: number;
    balance: number;
    currency: string;
};

export function SaasInvoiceStatus({ invoice }: { invoice: SaasInvoice }) {
    if (invoice.status === 'paid') {
        return <StatusChip tone="success">{invoice.statusLabel}</StatusChip>;
    }

    return invoice.overdue ? (
        <StatusChip tone="warning">Overdue</StatusChip>
    ) : (
        <StatusChip tone="info">{invoice.statusLabel}</StatusChip>
    );
}
