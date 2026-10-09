import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Field } from '@/features/inventory/fields';
import { formatDate } from '@/features/platform/format';
import { store, update } from '@/routes/out-of-service';

export type OutOfServicePeriod = {
    id: string;
    startsOn: string;
    returnsOn: string;
    reason: string;
    started: boolean;
};

type Errors = Partial<Record<string, string>>;

/** Dates are the property's calendar dates, so format them without shifting. */
const day = (date: string) => formatDate(date, 'UTC');

function PeriodFields({
    today,
    period,
    errors,
}: {
    today: string;
    period?: OutOfServicePeriod;
    errors: Errors;
}) {
    return (
        <>
            <InputError message={errors._property ?? errors.inventory} />
            <div className="grid gap-6 sm:grid-cols-2">
                <Field
                    id={`starts_on-${period?.id ?? 'new'}`}
                    label="Out of service from"
                    error={errors.starts_on}
                    hint={
                        period?.started
                            ? 'Started already, so the start stays.'
                            : undefined
                    }
                >
                    <Input
                        id={`starts_on-${period?.id ?? 'new'}`}
                        name="starts_on"
                        type="date"
                        required
                        min={period?.started ? undefined : today}
                        readOnly={period?.started}
                        className="tabular-nums"
                        defaultValue={period?.startsOn ?? today}
                    />
                </Field>
                <Field
                    id={`returns_on-${period?.id ?? 'new'}`}
                    label="Back in service on"
                    error={errors.returns_on}
                    hint="The first date the room can be sold again."
                >
                    <Input
                        id={`returns_on-${period?.id ?? 'new'}`}
                        name="returns_on"
                        type="date"
                        required
                        min={today}
                        className="tabular-nums"
                        defaultValue={period?.returnsOn}
                    />
                </Field>
                <Field
                    id={`reason-${period?.id ?? 'new'}`}
                    label="Reason"
                    error={errors.reason}
                    className="sm:col-span-2"
                >
                    <Input
                        id={`reason-${period?.id ?? 'new'}`}
                        name="reason"
                        required
                        maxLength={255}
                        defaultValue={period?.reason}
                    />
                </Field>
            </div>
        </>
    );
}

function ChangePeriod({
    period,
    today,
}: {
    period: OutOfServicePeriod;
    today: string;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Change
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Change out-of-service period</DialogTitle>
                <DialogDescription>
                    To end it early, set an earlier back-in-service date.
                </DialogDescription>
                <Form
                    {...update.form(period.id)}
                    disableWhileProcessing
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            <PeriodFields
                                today={today}
                                period={period}
                                errors={errors}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    Save period
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** A room's current and upcoming out-of-service periods, and the form to add one. */
export function OutOfService({
    roomId,
    periods,
    today,
}: {
    roomId: string;
    periods: OutOfServicePeriod[];
    today: string;
}) {
    return (
        <section
            aria-labelledby="out-of-service"
            className="flex flex-col gap-4 border-t pt-6"
        >
            <div className="space-y-1">
                <h2
                    id="out-of-service"
                    className="text-lg font-semibold tracking-tight"
                >
                    Out of service
                </h2>
                <p className="text-sm text-muted-foreground">
                    The room is not sold on these dates. Refused if it would
                    leave fewer sellable rooms than are already sold.
                </p>
            </div>

            {periods.length > 0 && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>From</TableHead>
                            <TableHead>Back on</TableHead>
                            <TableHead>Reason</TableHead>
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {periods.map((period) => (
                            <TableRow key={period.id}>
                                <TableCell className="tabular-nums">
                                    {day(period.startsOn)}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {day(period.returnsOn)}
                                </TableCell>
                                <TableCell className="break-words">
                                    {period.reason}
                                </TableCell>
                                <TableCell className="text-right">
                                    <ChangePeriod
                                        period={period}
                                        today={today}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}

            <Form
                {...store.form(roomId)}
                disableWhileProcessing
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="flex flex-col gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <PropertyIntent />
                        <PeriodFields today={today} errors={errors} />
                        <div className="flex justify-end">
                            <Button type="submit" variant="secondary">
                                {processing && <Spinner />}
                                Take out of service
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </section>
    );
}
