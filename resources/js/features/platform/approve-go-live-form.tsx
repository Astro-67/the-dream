import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { approve } from '@/routes/platform/properties/go-live';

export function ApproveGoLiveForm({ propertyId }: { propertyId: string }) {
    return (
        <Form
            {...approve.form(propertyId)}
            disableWhileProcessing
            errorBag={`approve-go-live-${propertyId}`}
            options={{ preserveScroll: true }}
            className="mt-2 flex flex-col items-start gap-1"
        >
            {({ errors }) => (
                <>
                    <Button type="submit" size="sm">
                        Approve go-live
                    </Button>
                    {Object.values(errors).map((error) => (
                        <InputError key={error} message={error} />
                    ))}
                </>
            )}
        </Form>
    );
}
