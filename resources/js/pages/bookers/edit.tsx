import { ContactForm, type Contact } from '@/features/guests/contact-pages';
import { show } from '@/routes/bookers';
export default function Edit({ record }: { record: Contact }) {
    return <ContactForm record={record} base="/bookers" title="Edit booker" />;
}

Edit.layout = ({ record }: { record: Contact }) => ({
    breadcrumbs: [
        { title: 'Bookers', href: '/bookers' },
        { title: record.name, href: show(record.id) },
        { title: 'Edit booker', href: '/bookers' },
    ],
});
