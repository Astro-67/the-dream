import { ContactShow } from '@/features/guests/contact-pages';
import { index } from '@/routes/bookers';
export default function Show(
    props: Omit<React.ComponentProps<typeof ContactShow>, 'base' | 'title'>,
) {
    return <ContactShow {...props} base="/bookers" title="booker" />;
}

Show.layout = {
    breadcrumbs: [
        { title: 'Bookers', href: index() },
        { title: 'Booker details', href: '#' },
    ],
};
