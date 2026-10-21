import { CountrySelect } from '@/components/shared/country-select';
import { EmptyState } from '@/components/ui/empty-state';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
    ['nationality', 'Country'],
    ['issuing_country', 'Issuing country'],
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
        <section className="space-y-4" aria-labelledby="identity-heading">
            <h2 id="identity-heading" className="text-lg font-semibold">
                Identity documents
            </h2>
            <p className="text-sm text-muted-foreground">
                Encrypted and visible only to staff with identity permission.
            </p>
            <InputError message={errors.documents} />
            {rows.map(({ key, document }, index) => (
                <fieldset key={key} className="space-y-4 rounded-xl border p-4">
                    <legend className="px-1 text-sm font-medium">
                        Document {index + 1}
                    </legend>
                    {fields.map(([field, label]) => {
                        const error = errors[`documents.${index}.${field}`];
                        const id = `document-${key}-${field}`;
                        return (
                            <div key={field} className="space-y-2">
                                <Label htmlFor={id}>{label}</Label>
                                {field === 'nationality' ||
                                field === 'issuing_country' ? (
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
                                        required={
                                            field === 'type' ||
                                            field === 'number'
                                        }
                                        maxLength={field === 'type' ? 50 : 255}
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
        <section className="space-y-4" aria-labelledby="identity-heading">
            <h2 id="identity-heading" className="text-lg font-semibold">
                Identity documents
            </h2>
            {!canViewIdentity && (
                <p className="text-sm text-muted-foreground">
                    Identity fields are masked. Identity permission is required
                    to view them.
                </p>
            )}
            {documents.length === 0 ? (
                <EmptyState message="No identity documents recorded." />
            ) : (
                documents.map((document, index) => (
                    <dl
                        key={index}
                        className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2"
                    >
                        {fields.map(([field, label]) => (
                            <div key={field}>
                                <dt className="text-sm text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="break-words">
                                    {document[field] ?? '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                ))
            )}
        </section>
    );
}
