import { Form } from '@inertiajs/react';
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
    status: 'filed' | 'refused' | 'unknown' | 'waiting';
    attempts: number;
    number: string | null;
    message: string | null;
    refileUrl: string | null;
    retryUrl: string | null;
};

const statuses: Record<VfmsFiling['status'], [string, StatusTone]> = {
    filed: ['Filed', 'success'],
    refused: ['Refused', 'error'],
    unknown: ['Unknown', 'warning'],
    waiting: ['Waiting', 'info'],
};

export function VfmsFilingsPanel({ filings }: { filings: VfmsFiling[] }) {
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
                                                filing={filing}
                                                url={filing.refileUrl}
                                                action="Re-file"
                                            />
                                        )}
                                        {filing.retryUrl && (
                                            <RecoverFiling
                                                filing={filing}
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
            </CollapsibleContent>
        </Collapsible>
    );
}

function RecoverFiling({
    filing,
    url,
    action,
}: {
    filing: VfmsFiling;
    url: string;
    action: 'Re-file' | 'Retry';
}) {
    const id = `vfms-${action === 'Retry' ? 'retry' : 'refile'}-${filing.id}`;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="min-h-11">
                    {action}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {action} {filing.call} with ZRA?
                    </DialogTitle>
                    <DialogDescription>
                        {action === 'Retry'
                            ? 'This sends the same filing to ZRA again.'
                            : 'This sends corrected stay details to ZRA. Only a confirmed rejection can be re-filed; an unknown outcome needs reconciliation.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={url}
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
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {action} with ZRA
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
