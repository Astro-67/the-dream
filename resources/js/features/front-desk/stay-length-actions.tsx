import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { departEarly, extend } from '@/routes/reservations';
import { Spinner } from '@/components/ui/spinner';

/** Extend an in-house stay, or end it today. */
export function StayLengthActions({
    number,
    departure,
}: {
    number: string;
    departure: string;
}) {
    const [departOpen, setDepartOpen] = useState(false);

    return (
        <div className="flex flex-wrap gap-2">
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="outline">Extend stay</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Extend stay</DialogTitle>
                        <DialogDescription>
                            The new nights keep the same room and are priced
                            from the rate plan.
                        </DialogDescription>
                    </DialogHeader>
                    <Form {...extend.form(number)} className="space-y-4">
                        {({ errors, processing }) => (
                            <>
                                <PropertyIntent />
                                <div className="space-y-2">
                                    <Label htmlFor="extend-departure">
                                        New departure
                                    </Label>
                                    <Input
                                        id="extend-departure"
                                        name="departure"
                                        type="date"
                                        required
                                        min={departure.slice(0, 10)}
                                        aria-invalid={Boolean(errors.departure)}
                                        aria-describedby="extend-departure-error"
                                    />
                                    <div id="extend-departure-error">
                                        <InputError
                                            message={errors.departure}
                                        />
                                    </div>
                                </div>
                                <InputError message={errors.reservation} />
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            <span className="size-4">
                                                {processing && <Spinner />}
                                            </span>
                                            Keep dates
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Extend stay
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
            <Dialog open={departOpen} onOpenChange={setDepartOpen}>
                <DialogTrigger asChild>
                    <Button variant="outline">Depart early</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Depart reservation <Identifier value={number} />{' '}
                            early today?
                        </DialogTitle>
                        <DialogDescription>
                            The departure moves to today. Tonight and later
                            nights are released for sale and their prices
                            removed.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...departEarly.form(number)}
                        onSuccess={() => setDepartOpen(false)}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <PropertyIntent />
                                <InputError message={errors.reservation} />
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            <span className="size-4">
                                                {processing && <Spinner />}
                                            </span>
                                            Keep dates
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        Depart early
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
