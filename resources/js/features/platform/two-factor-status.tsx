import { StatusChip } from '@/components/ui/status-chip';

/** Status chip (design.md): a dot and a label, never colour alone. */
export function TwoFactorStatus({ enabled }: { enabled: boolean }) {
    return (
        <StatusChip tone={enabled ? 'success' : 'warning'}>
            {enabled ? 'On' : 'Not set up'}
        </StatusChip>
    );
}
