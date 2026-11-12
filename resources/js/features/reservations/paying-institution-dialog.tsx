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
import { NativeSelect } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { payingInstitution } from '@/routes/reservations';

export function PayingInstitutionDialog({
    reservation,
}: {
    reservation: {
        reservation_number: string;
        paying_institution_type: 'government' | 'business' | null;
        paying_institution_name: string | null;
        paying_institution_zrb_number: string | null;
    };
}) {
    const [open, setOpen] = useState(false);
    const [type, setType] = useState(reservation.paying_institution_type ?? '');
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="secondary" className="min-h-11">
                    Edit paying institution
                </Button>
            </DialogTrigger>
            <DialogContent aria-describedby="paying-institution-description">
                <DialogHeader>
                    <DialogTitle>Paying institution</DialogTitle>
                    <DialogDescription id="paying-institution-description">
                        Applies to every room of this booking group that ZRA has
                        not accepted. This sends nothing to ZRA; after a refused
                        check-in, use Re-file.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...payingInstitution.form(reservation.reservation_number)}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <div className="space-y-2">
                                <Label htmlFor="edit_paying_institution_type">
                                    Paying institution
                                </Label>
                                <NativeSelect
                                    id="edit_paying_institution_type"
                                    name="paying_institution_type"
                                    value={type}
                                    onChange={(event) =>
                                        setType(event.target.value)
                                    }
                                    aria-describedby="paying-institution-type-error"
                                >
                                    <option value="">None</option>
                                    <option value="government">
                                        Government institution (withholding)
                                    </option>
                                    <option value="business">
                                        Registered business (B2B)
                                    </option>
                                </NativeSelect>
                                <div id="paying-institution-type-error">
                                    <InputError
                                        message={errors.paying_institution_type}
                                    />
                                </div>
                            </div>
                            {type && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="edit_paying_institution_name">
                                            Institution name
                                        </Label>
                                        <Input
                                            id="edit_paying_institution_name"
                                            name="paying_institution_name"
                                            defaultValue={
                                                reservation.paying_institution_name ??
                                                ''
                                            }
                                            required
                                            maxLength={255}
                                            aria-describedby="paying-institution-name-error"
                                        />
                                        <div id="paying-institution-name-error">
                                            <InputError
                                                message={
                                                    errors.paying_institution_name
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="edit_paying_institution_zrb_number">
                                            ZRB number
                                        </Label>
                                        <Input
                                            id="edit_paying_institution_zrb_number"
                                            name="paying_institution_zrb_number"
                                            defaultValue={
                                                reservation.paying_institution_zrb_number ??
                                                ''
                                            }
                                            required
                                            maxLength={255}
                                            aria-describedby="paying-institution-zrb-error"
                                        />
                                        <div id="paying-institution-zrb-error">
                                            <InputError
                                                message={
                                                    errors.paying_institution_zrb_number
                                                }
                                            />
                                        </div>
                                    </div>
                                </>
                            )}
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
                                        Keep as is
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Save paying institution
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
