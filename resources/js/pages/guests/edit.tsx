import { ContactForm, type Contact } from '@/features/guests/contact-pages';
import { index, show } from '@/routes/guests';
import {
    IdentityFields,
    IdentityDetails,
    type IdentityDocument,
} from '@/features/guests/identity-documents';
export default function Edit({
    record,
    documents,
    canViewIdentity,
}: {
    record: Contact;
    documents: IdentityDocument[];
    canViewIdentity: boolean;
}) {
    return (
        <ContactForm
            record={record}
            base="/guests"
            title="Edit guest"
            identityEditable={canViewIdentity}
        >
            {(errors) =>
                canViewIdentity ? (
                    <IdentityFields documents={documents} errors={errors} />
                ) : (
                    <IdentityDetails
                        documents={documents}
                        canViewIdentity={false}
                    />
                )
            }
        </ContactForm>
    );
}
Edit.layout = ({ record }: { record: Contact }) => ({
    breadcrumbs: [
        { title: 'Guests', href: index() },
        { title: record.name, href: show(record.id) },
        { title: 'Edit guest', href: '#' },
    ],
});
