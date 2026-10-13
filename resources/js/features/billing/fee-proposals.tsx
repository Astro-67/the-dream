import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusChip } from '@/components/ui/status-chip';
import type { StatusTone } from '@/components/ui/status-chip';
import { formatMoney } from '@/lib/utils';
import { resolve } from '@/routes/fee-proposals';
import { Spinner } from '@/components/ui/spinner';

export type FeeProposal = {
    id: string;
    kind: 'cancellation' | 'no_show';
    amount: number;
    currency: string;
    status: 'proposed' | 'posted' | 'waived';
    reason: string | null;
    fiscal_exception_candidate: boolean;
    can_resolve: boolean;
};

const kinds = { cancellation: 'Cancellation fee', no_show: 'No-show fee' };
const statuses: Record<FeeProposal['status'], [string, StatusTone]> = {
    proposed: ['Proposed', 'warning'],
    posted: ['Posted', 'success'],
    waived: ['Waived', 'info'],
};

/** Fees the cancellation policy proposed; staff post or waive each with a reason. */
export function FeeProposals({ fees }: { fees: FeeProposal[] }) {
    if (fees.length === 0) return null;

    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">Fees</h2>
            {fees.map((fee) => (
                <div key={fee.id} className="max-w-[720px] space-y-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="font-medium">{kinds[fee.kind]}</span>
                        <span className="tabular-nums">
                            {formatMoney(fee.amount, fee.currency)}
                        </span>
                        <StatusChip tone={statuses[fee.status][1]}>
                            {statuses[fee.status][0]}
                        </StatusChip>
                        {fee.fiscal_exception_candidate && (
                            <StatusChip tone="info">
                                Fiscal exception
                            </StatusChip>
                        )}
                    </div>
                    {fee.reason && (
                        <p className="text-sm text-muted-foreground">
                            {fee.reason}
                        </p>
                    )}
                    {fee.can_resolve && <ResolveFeeForm fee={fee} />}
                </div>
            ))}
        </section>
    );
}

function ResolveFeeForm({ fee }: { fee: FeeProposal }) {
    const id = `fee-${fee.id}-reason`;

    return (
        <Form
            {...resolve.form(fee.id)}
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ errors, processing }) => (
                <>
                    <PropertyIntent />
                    <div className="space-y-2">
                        <Label htmlFor={id}>Reason</Label>
                        <Input
                            id={id}
                            name="reason"
                            required
                            maxLength={500}
                            aria-invalid={Boolean(errors.reason)}
                            aria-describedby={`${id}-error`}
                        />
                        <div id={`${id}-error`}>
                            <InputError message={errors.reason} />
                        </div>
                    </div>
                    <InputError message={errors.fee ?? errors.decision} />
                    <div className="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            name="decision"
                            value="post"
                            disabled={processing}
                        >
                            {processing && <Spinner />}Post fee
                        </Button>
                        <Button
                            type="submit"
                            name="decision"
                            value="waive"
                            variant="outline"
                            disabled={processing}
                        >
                            <span className="size-4">
                                {processing && <Spinner />}
                            </span>
                            Waive fee
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
