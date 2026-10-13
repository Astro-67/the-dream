import { Form } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import type { ReactNode } from 'react';
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
import { destroy } from '@/routes/members';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    member: { id: string; name: string; isYou: boolean };
    trigger: ReactNode;
};

/** Confirms removing a member (or leaving, for yourself). Owners only. */
export function RemoveMember({ member, trigger }: Props) {
    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {member.isYou
                        ? 'Leave the organization?'
                        : `Remove ${member.name}?`}
                </DialogTitle>
                <DialogDescription>
                    {member.isYou
                        ? 'You lose access to every property at once, including pages you have open.'
                        : 'They lose access to every property at once, including pages they have open. You can invite them again later.'}
                </DialogDescription>
                <Form
                    {...destroy.form(member.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <PropertyIntent />
                            {(errors.member || errors._property) && (
                                <p
                                    role="alert"
                                    className="mb-4 flex gap-2 text-[13px] text-status-error"
                                >
                                    <AlertCircle
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    {errors.member ?? errors._property}
                                </p>
                            )}
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                    type="submit"
                                >
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    {member.isYou
                                        ? 'Leave organization'
                                        : 'Remove member'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
