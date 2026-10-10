import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import type { Paginated } from '@/types';

export type Contact = { id: string; name: string; email: string | null; phone: string | null; nationality: string | null; preferences: string | null; notes: string | null };
const fields = [ ['name', 'Name'], ['email', 'Email'], ['phone', 'Phone'], ['nationality', 'Nationality (two-letter country code)'], ['preferences', 'Preferences'], ['notes', 'Notes'] ] as const;
export function ContactIndex({ records, canManage, base, title }: { records: Paginated<Contact>; canManage: boolean; base: string; title: string }) {
    return <><Head title={title} /><div className="flex flex-col gap-6 p-4 md:p-6"><header className="flex flex-wrap items-center justify-between gap-4"><h1 className="text-2xl font-semibold">{title}</h1>{canManage && <Button asChild><Link href={`${base}/create`}>Add {title.toLowerCase().slice(0, -1)}</Link></Button>}</header>{records.data.length === 0 ? <EmptyState message={`No ${title.toLowerCase()} yet. Add a record for this property.`} action={canManage && <Button asChild><Link href={`${base}/create`}>Add a record</Link></Button>} /> : <><Table><TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Email</TableHead><TableHead>Phone</TableHead><TableHead>Nationality</TableHead></TableRow></TableHeader><TableBody>{records.data.map(record => <TableRow key={record.id}><TableCell><Link className="text-primary underline underline-offset-4" href={`${base}/${record.id}`}>{record.name}</Link></TableCell><TableCell>{record.email ?? '—'}</TableCell><TableCell>{record.phone ?? '—'}</TableCell><TableCell>{record.nationality ?? '—'}</TableCell></TableRow>)}</TableBody></Table><Pagination page={records} /></>}</div></>;
}
export function ContactForm({ record, base, title, children }: { record?: Contact; base: string; title: string; children?: React.ReactNode }) {
    return <><Head title={title} /><div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6"><h1 className="text-2xl font-semibold">{title}</h1><Form action={record ? `${base}/${record.id}` : base} method={record ? 'put' : 'post'} disableWhileProcessing className="flex flex-col gap-6">{({ processing, errors }) => <><PropertyIntent /><InputError message={errors._property} />{fields.map(([field, label]) => <div key={field} className="space-y-2"><Label htmlFor={field}>{label}</Label><Input id={field} name={field} type={field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text'} defaultValue={record?.[field] ?? ''} required={field === 'name'} maxLength={field === 'nationality' ? 2 : field === 'phone' ? 50 : field === 'preferences' || field === 'notes' ? 5000 : 255} aria-invalid={Boolean(errors[field])} aria-describedby={errors[field] ? `${field}-error` : undefined} /><InputError id={`${field}-error`} message={errors[field]} /></div>)}{children}<div className="flex justify-end gap-3"><Button variant="ghost" asChild><Link href={base}>Cancel</Link></Button><Button type="submit">{processing && <Spinner />}Save</Button></div></>}</Form></div></>;
}
export function ContactShow({ record, canManage, base, title, children }: { record: Contact; canManage: boolean; base: string; title: string; children?: React.ReactNode }) {
    return <><Head title={record.name} /><div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6"><header className="flex items-center justify-between gap-4"><h1 className="text-2xl font-semibold">{record.name}</h1>{canManage && <Button asChild><Link href={`${base}/${record.id}/edit`}>Edit {title}</Link></Button>}</header><dl className="grid gap-4">{fields.map(([field, label]) => <div key={field}><dt className="text-sm text-muted-foreground">{label}</dt><dd className="whitespace-pre-wrap break-words">{record[field] ?? '—'}</dd></div>)}</dl>{children}<Button variant="ghost" asChild><Link href={base}>Back to {title}s</Link></Button></div></>;
}
