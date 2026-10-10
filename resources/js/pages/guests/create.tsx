import { ContactForm } from '@/features/guests/contact-pages';
import { IdentityFields } from '@/features/guests/identity-documents';
export default function Create({
    canViewIdentity,
}: {
    canViewIdentity: boolean;
}) {
    return (
        <ContactForm
            base="/guests"
            title="Add guest"
            identityEditable={canViewIdentity}
        >
            {(errors) => canViewIdentity && <IdentityFields errors={errors} />}
        </ContactForm>
    );
}
Create.layout = {
    breadcrumbs: [
        { title: 'Guests', href: '/guests' },
        { title: 'Add guest', href: '/guests/create' },
    ],
};
