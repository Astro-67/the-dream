import { ContactForm, type Contact } from '@/features/guests/contact-pages';
export default function Edit({ record }: { record: Contact }) { return <ContactForm record={record} base="/bookers" title="Edit booker" />; }
