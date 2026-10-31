import { StatusChip } from '@/components/ui/status-chip';
import type { StatusTone } from '@/components/ui/status-chip';

const tones: Record<string, StatusTone> = {
    trial: 'info',
    active: 'success',
    past_due: 'warning',
    suspended: 'error',
    cancelled: 'unavailable',
};

export function SubscriptionStatus({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return <StatusChip tone={tones[status] ?? 'info'}>{label}</StatusChip>;
}
