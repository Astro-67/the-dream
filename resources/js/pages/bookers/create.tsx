import { ContactForm } from '@/features/guests/contact-pages';
export default function Create() {
    return <ContactForm base="/bookers" title="Add booker" />;
}

Create.layout = {
    breadcrumbs: [
        { title: 'Bookers', href: '/bookers' },
        { title: 'Add booker', href: '/bookers' },
    ],
};
