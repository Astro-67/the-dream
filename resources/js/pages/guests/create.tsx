import { ContactForm } from '@/features/guests/contact-pages';
import { IdentityFields } from '@/features/guests/identity-documents';
import { index } from '@/routes/guests';
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
        { title: 'Guests', href: index() },
        { title: 'Add guest', href: '#' },
    ],
};
