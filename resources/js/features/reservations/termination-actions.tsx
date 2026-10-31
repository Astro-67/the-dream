import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
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
import { cancel, voidMethod } from '@/routes/reservations';
import { Spinner } from '@/components/ui/spinner';

export function TerminationActions({
    number,
    can,
}: {
    number: string;
    can: { cancel: boolean; void: boolean };
}) {
    const [openAction, setOpenAction] = useState<string | null>(null);
    return (
        <div className="flex flex-wrap gap-2">
            {(['cancel', 'void'] as const).map((action) => {
                if (!can[action]) return null;
                const label = action === 'cancel' ? 'Cancel' : 'Void';
                return (
                    <Dialog
                        key={action}
                        open={openAction === action}
                        onOpenChange={(open) =>
                            setOpenAction(open ? action : null)
                        }
                    >
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                {label} reservation
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle className="break-words">
                                    {label} reservation {number}?
                                </DialogTitle>
                                <DialogDescription>
                                    {action === 'cancel'
                                        ? 'Cancel this reservation and release its inventory and room assignment. This cannot be undone.'
                                        : 'Mark this reservation as created in error and release its inventory and room assignment. This cannot be undone.'}
                                </DialogDescription>
                            </DialogHeader>
                            <Form
                                onSuccess={() => setOpenAction(null)}
                                {...(action === 'cancel'
                                    ? cancel
                                    : voidMethod
                                ).form(number)}
                                className="space-y-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <PropertyIntent />
                                        <div className="space-y-2">
                                            <Label htmlFor={`${action}-reason`}>
                                                Reason
                                            </Label>
                                            <Input
                                                id={`${action}-reason`}
                                                name="reason"
                                                required
                                                maxLength={2000}
                                                aria-invalid={Boolean(
                                                    errors.reason,
                                                )}
                                                aria-describedby={`${action}-reason-error`}
                                            />
                                            <div id={`${action}-reason-error`}>
                                                <InputError
                                                    message={errors.reason}
                                                />
                                            </div>
                                        </div>
                                        <InputError
                                            message={errors.reservation}
                                        />
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    <span className="size-4">
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                    </span>
                                                    Keep reservation
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {label} reservation
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                );
            })}
        </div>
    );
}
