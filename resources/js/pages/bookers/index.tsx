import { ContactIndex } from '@/features/guests/contact-pages';
export default function Index(
    props: Omit<React.ComponentProps<typeof ContactIndex>, 'base' | 'title'>,
) {
    return <ContactIndex {...props} base="/bookers" title="Bookers" />;
}

Index.layout = { breadcrumbs: [{ title: 'Bookers', href: '/bookers' }] };
