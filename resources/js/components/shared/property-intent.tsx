import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/** The form field the server's `property.intent` middleware reads. */
export const PROPERTY_INTENT_FIELD = '_property';

/**
 * The property a form was opened for: the current property when the form
 * mounted (or `propertyId` when the form is about a specific property). It is
 * fixed at mount, so a switch in another tab never retargets an open form.
 * Use it with useForm: `useForm({ ...usePropertyIntent(), name: '' })`.
 */
export function usePropertyIntent(propertyId?: string): {
    [PROPERTY_INTENT_FIELD]: string;
} {
    const { tenant } = usePage().props;
    const [intended] = useState(() => propertyId ?? tenant.property?.id ?? '');

    return { [PROPERTY_INTENT_FIELD]: intended };
}

/**
 * Put inside every <Form> that changes data on a `property.intent` route. The
 * server saves the submission to this property if the member may still act in
 * it, or refuses it; never to a property selected since.
 */
export function PropertyIntent({ propertyId }: { propertyId?: string }) {
    const intent = usePropertyIntent(propertyId);

    return (
        <input
            type="hidden"
            name={PROPERTY_INTENT_FIELD}
            value={intent[PROPERTY_INTENT_FIELD]}
        />
    );
}
