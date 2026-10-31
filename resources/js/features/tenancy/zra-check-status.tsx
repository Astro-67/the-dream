import { StatusChip } from '@/components/ui/status-chip';
import { formatDate } from '@/features/platform/format';
import { formatTime } from '@/lib/utils';

const outcomes = {
    accepted: { tone: 'success', label: 'Accepted by ZRA' },
    refused: { tone: 'error', label: 'Refused by ZRA' },
    unreachable: { tone: 'warning', label: 'Could not reach ZRA' },
} as const;

export function ZraCheckStatus({
    outcome,
    checkedAt,
    timeZone = 'UTC',
}: {
    outcome: keyof typeof outcomes | null;
    checkedAt: string | null;
    timeZone?: string;
}) {
    return outcome && checkedAt ? (
        <>
            <StatusChip tone={outcomes[outcome].tone}>
                {outcomes[outcome].label}
            </StatusChip>
            <span className="text-sm text-muted-foreground">
                {formatDate(checkedAt, timeZone)}{' '}
                {formatTime(checkedAt, timeZone)}
            </span>
        </>
    ) : (
        <span className="text-sm text-muted-foreground">
            Not checked with ZRA yet.
        </span>
    );
}
