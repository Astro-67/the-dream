import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { checkIn } from '@/routes/reservations';

export function CheckInForm({ number }: { number: string }) {
    return (
        <Form
            {...checkIn.form(number)}
            disableWhileProcessing
            errorBag={`check-in-${number}`}
            options={{ preserveScroll: true }}
            className="flex flex-col items-start gap-1"
        >
            {({ errors }) => (
                <>
                    <PropertyIntent />
                    <Button type="submit">Check in</Button>
                    {Object.values(errors).map((error) => (
                        <InputError key={error} message={error} />
                    ))}
                </>
            )}
        </Form>
    );
}
