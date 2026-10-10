import { ContactShow } from '@/features/guests/contact-pages';
export default function Show(props: Omit<React.ComponentProps<typeof ContactShow>, 'base' | 'title'>) { return <ContactShow {...props} base="/bookers" title="booker" />; }
