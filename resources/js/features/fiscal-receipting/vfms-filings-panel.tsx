import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip, type StatusTone } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export type VfmsFiling = {
    id: string;
    call: string;
    status: 'filed' | 'sandbox' | 'closed' | 'refused' | 'unknown' | 'waiting';
    attempts: number;
    number: string | null;
    message: string | null;
    refileUrl: string | null;
    retryUrl: string | null;
};

const statuses: Record<VfmsFiling['status'], [string, StatusTone]> = {
    filed: ['Filed', 'success'],
    sandbox: ['Sandbox — not fiscal', 'info'],
    closed: ['Closed without filing', 'unavailable'],
    refused: ['Refused', 'error'],
    unknown: ['Unknown', 'warning'],
    waiting: ['Waiting', 'info'],
};

export function VfmsFilingsPanel({
    filings,
    closeUrl,
    fiscalExceptionReason,
}: {
    filings: VfmsFiling[];
    closeUrl?: string | null;
    fiscalExceptionReason?: string | null;
}) {
    if (filings.length === 0) return null;

    return (
        <Collapsible
            defaultOpen={filings.some((filing) => filing.status !== 'filed')}
        >
            <h2>
                <CollapsibleTrigger asChild>
                    <Button
                        variant="ghost"
                        className="group -mx-3 h-auto min-h-11 max-w-full justify-start text-left text-lg font-semibold whitespace-normal"
                    >
                        ZRA filings ({filings.length})
                        <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                    </Button>
                </CollapsibleTrigger>
            </h2>
            <CollapsibleContent className="pt-4">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Call</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead numeric>Attempts</TableHead>
                            <TableHead>ZRA number</TableHead>
                            <TableHead>ZRA message</TableHead>
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {filings.map((filing) => {
                            const [label, tone] = statuses[filing.status];
                            return (
                                <TableRow key={filing.id}>
                                    <TableCell>{filing.call}</TableCell>
                                    <TableCell>
                                        <StatusChip tone={tone}>
                                            {label}
                                        </StatusChip>
                                    </TableCell>
                                    <TableCell numeric>
                                        {filing.attempts}
                                    </TableCell>
                                    <TableCell>
                                        {filing.number && (
                                            <Identifier
                                                value={filing.number}
                                                copyable
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-normal">
                                        {filing.message}
                                    </TableCell>
                                    <TableCell>
                                        {filing.refileUrl && (
                                            <RecoverFiling
                                                id={filing.id}
                                                call={filing.call}
                                                url={filing.refileUrl}
                                                action="Re-file"
                                            />
                                        )}
                                        {filing.retryUrl && (
                                            <RecoverFiling
                                                id={filing.id}
                                                call={filing.call}
                                                url={filing.retryUrl}
                                                action="Retry"
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
                {fiscalExceptionReason && (
                    <p className="mt-4 text-sm">
                        Fiscal exception: {fiscalExceptionReason}. The property
                        must declare this stay in its VAT return.
                    </p>
                )}
                {closeUrl && (
                    <div className="mt-4">
                        <RecoverFiling
                            id="stay"
                            call=""
                            url={closeUrl}
                            action="Close without filing"
                        />
                    </div>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function RecoverFiling({
    id: filingId,
    call,
    url,
    action,
}: {
    id: string;
    call: string;
    url: string;
    action: 'Re-file' | 'Retry' | 'Close without filing';
}) {
    const id = `vfms-${action === 'Close without filing' ? 'close' : action === 'Retry' ? 'retry' : 'refile'}-${filingId}`;
    const closing = action === 'Close without filing';
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="min-h-11">
                    {action}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {closing
                            ? 'Close this stay without filing?'
                            : `${action} ${call} with ZRA?`}
                    </DialogTitle>
                    <DialogDescription>
                        {closing
                            ? 'Nothing will be filed with ZRA. This departed stay becomes a fiscal exception which the property must declare in its VAT return. Existing filing evidence is kept.'
                            : action === 'Retry'
                              ? 'This sends the same filing to ZRA again.'
                              : 'This sends corrected stay details to ZRA. Only a confirmed rejection can be re-filed; an unknown outcome needs reconciliation.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={url}
                    onSuccess={() => setOpen(false)}
                    method="post"
                    className="space-y-4"
                    resetOnSuccess
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <div className="space-y-2">
                                <Label htmlFor={id}>Reason</Label>
                                <Input
                                    id={id}
                                    name="reason"
                                    defaultValue={
                                        closing
                                            ? 'Departed before VFMS filing worked'
                                            : undefined
                                    }
                                    required
                                    maxLength={500}
                                    aria-invalid={Boolean(errors.reason)}
                                    aria-describedby={`${id}-error`}
                                />
                                <InputError
                                    id={`${id}-error`}
                                    message={errors.reason}
                                />
                            </div>
                            <InputError message={errors.vfms} />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {closing ? action : `${action} with ZRA`}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
