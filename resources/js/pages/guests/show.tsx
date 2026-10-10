import { ContactShow, type Contact } from '@/features/guests/contact-pages';
import {
    IdentityDetails,
    type IdentityDocument,
} from '@/features/guests/identity-documents';
export default function Show({
    record,
    canManage,
    documents,
    canViewIdentity,
}: {
    record: Contact;
    canManage: boolean;
    documents: IdentityDocument[];
    canViewIdentity: boolean;
}) {
    return (
        <ContactShow
            record={record}
            canManage={canManage}
            base="/guests"
            title="guest"
        >
            <IdentityDetails
                documents={documents}
                canViewIdentity={canViewIdentity}
            />
        </ContactShow>
    );
}
Show.layout = {
    breadcrumbs: [
        { title: 'Guests', href: '/guests' },
        { title: 'Guest details', href: '/guests' },
    ],
};
