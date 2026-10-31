import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Input } from '@/components/ui/input';
import { declineGoLive } from '@/routes/platform/properties';

export function DeclineGoLive({
    property,
}: {
    property: { id: string; name: string };
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="secondary" size="sm" className="mt-2">
                    Decline
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Decline go-live for {property.name}?</DialogTitle>
                <DialogDescription>
                    The property stays in setup. Its owner sees your reason and
                    can request go-live again.
                </DialogDescription>
                <Form
                    {...declineGoLive.form(property.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing, errors }) => (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor={`decline-reason-${property.id}`}
                                >
                                    Reason
                                </Label>
                                <Input
                                    id={`decline-reason-${property.id}`}
                                    name="reason"
                                    required
                                    maxLength={2000}
                                    aria-invalid={!!errors.reason}
                                    aria-describedby={`decline-error-${property.id}`}
                                />
                                <InputError
                                    id={`decline-error-${property.id}`}
                                    message={errors.reason}
                                />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Decline go-live
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
