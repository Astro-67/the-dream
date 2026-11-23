import { CountrySelect } from '@/components/shared/country-select';
import { EmptyState } from '@/components/ui/empty-state';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { IdCard, ShieldCheck } from 'lucide-react';

export type IdentityDocument = {
    type: string;
    number: string;
    nationality: string | null;
    issuing_country: string | null;
    expiry: string | null;
};
const fields = [
    ['type', 'Document type'],
    ['number', 'Document number'],
    ['nationality', 'Guest nationality'],
    ['expiry', 'Expiry date'],
] as const;

export function IdentityFields({
    documents = [],
    errors,
}: {
    documents?: IdentityDocument[];
    errors: Record<string, string>;
}) {
    const [rows, setRows] = useState(
        documents.map((document, key) => ({ key, document })),
    );
    const [nextKey, setNextKey] = useState(documents.length);
    return (
        <section
            className="space-y-4 border-t pt-6"
            aria-labelledby="identity-heading"
        >
            <h2 id="identity-heading" className="text-lg font-semibold">
                Identity documents
            </h2>
            <p className="text-sm text-muted-foreground">
                Encrypted and visible only to staff with identity permission.
            </p>
            <InputError message={errors.documents} />
            {rows.map(({ key, document }, index) => (
                <fieldset
                    key={key}
                    className="grid gap-4 border border-border bg-muted/20 p-4 sm:grid-cols-2"
                >
                    <legend className="px-1 text-sm font-semibold sm:col-span-2">
                        Document {index + 1}
                    </legend>
                    <input
                        type="hidden"
                        name={`documents[${index}][issuing_country]`}
                        value={document.issuing_country ?? ''}
                    />
                    {fields.map(([field, label]) => {
                        const error = errors[`documents.${index}.${field}`];
                        const id = `document-${key}-${field}`;
                        return (
                            <div
                                key={field}
                                className={`grid content-start gap-2 ${field === 'type' || field === 'number' ? 'sm:col-span-1' : ''}`}
                            >
                                <Label htmlFor={id}>{label}</Label>
                                {field === 'type' ? (
                                    <NativeSelect
                                        id={id}
                                        name={`documents[${index}][${field}]`}
                                        defaultValue={document.type}
                                        required
                                        aria-invalid={Boolean(error)}
                                        aria-describedby={
                                            error ? `${id}-error` : undefined
                                        }
                                    >
                                        {![
                                            'passport',
                                            'nida',
                                            'zanid',
                                        ].includes(document.type) && (
                                            <option value={document.type}>
                                                {document.type}
                                            </option>
                                        )}
                                        <option value="">
                                            Choose document
                                        </option>
                                        <option value="passport">
                                            Passport
                                        </option>
                                        <option value="nida">NIDA</option>
                                        <option value="zanid">ZanID</option>
                                    </NativeSelect>
                                ) : field === 'nationality' ? (
                                    <CountrySelect
                                        id={id}
                                        name={`documents[${index}][${field}]`}
                                        defaultValue={document[field] ?? ''}
                                        aria-invalid={Boolean(error)}
                                        aria-describedby={
                                            error ? `${id}-error` : undefined
                                        }
                                    />
                                ) : (
                                    <Input
                                        id={id}
                                        name={`documents[${index}][${field}]`}
                                        type={
                                            field === 'expiry' ? 'date' : 'text'
                                        }
                                        defaultValue={document[field] ?? ''}
                                        required={field === 'number'}
                                        maxLength={255}
                                        autoComplete="off"
                                        aria-invalid={Boolean(error)}
                                        aria-describedby={
                                            error ? `${id}-error` : undefined
                                        }
                                    />
                                )}
                                <InputError
                                    id={`${id}-error`}
                                    message={error}
                                />
                            </div>
                        );
                    })}
                    <Button
                        type="button"
                        variant="outline"
                        className="sm:col-span-2 sm:justify-self-start"
                        onClick={() =>
                            setRows(rows.filter((row) => row.key !== key))
                        }
                    >
                        Remove document {index + 1}
                    </Button>
                </fieldset>
            ))}
            <Button
                type="button"
                variant="outline"
                disabled={rows.length >= 10}
                onClick={() => {
                    setRows([
                        ...rows,
                        {
                            key: nextKey,
                            document: {
                                type: '',
                                number: '',
                                nationality: '',
                                issuing_country: '',
                                expiry: '',
                            },
                        },
                    ]);
                    setNextKey(nextKey + 1);
                }}
            >
                Add identity document
            </Button>
        </section>
    );
}

export function IdentityDetails({
    documents,
    canViewIdentity,
}: {
    documents: IdentityDocument[];
    canViewIdentity: boolean;
}) {
    return (
        <section
            className="overflow-hidden border border-border bg-card shadow-panel"
            aria-labelledby="identity-heading"
        >
            <div className="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-4 md:px-5">
                <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                    <IdCard aria-hidden="true" className="size-5" />
                </span>
                <div>
                    <h2
                        id="identity-heading"
                        className="text-base font-semibold"
                    >
                        Identity documents
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Sensitive guest records
                    </p>
                </div>
            </div>
            <div className="space-y-4 p-4 md:p-5">
                {!canViewIdentity && (
                    <p className="flex items-start gap-2 border border-status-info/20 bg-status-info-tint px-3 py-2.5 text-sm text-status-info">
                        <ShieldCheck
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <span>
                            Identity fields are masked. Identity permission is
                            required to view them.
                        </span>
                    </p>
                )}
                {documents.length === 0 ? (
                    <EmptyState message="No identity documents recorded." />
                ) : (
                    <div className="grid gap-3 xl:grid-cols-2">
                        {documents.map((document, index) => (
                            <dl
                                key={index}
                                className="grid gap-x-4 gap-y-3 border border-border bg-muted/20 p-4 sm:grid-cols-2"
                            >
                                <div className="sm:col-span-2">
                                    <dt className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                        Document {index + 1}
                                    </dt>
                                </div>
                                {fields.map(([field, label]) => (
                                    <div key={field} className="min-w-0">
                                        <dt className="text-xs text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd
                                            className={`mt-1 wrap-anywhere ${field === 'number' ? 'font-mono text-base font-semibold' : 'text-sm'}`}
                                        >
                                            {document[field] ?? '\u2014'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        ))}
                    </div>
                )}
            </div>
        </section>
    );
}
