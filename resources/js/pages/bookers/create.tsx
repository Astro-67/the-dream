import { ContactForm } from '@/features/guests/contact-pages';
import { index } from '@/routes/bookers';
export default function Create() {
    return <ContactForm base="/bookers" title="Add booker" />;
}

Create.layout = {
    breadcrumbs: [
        { title: 'Bookers', href: index() },
        { title: 'Add booker', href: '#' },
    ],
};
