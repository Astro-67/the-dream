import { CountrySelect } from '@/components/shared/country-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';

type Field = 'name' | 'nationality' | 'document_type' | 'document_number';

/** One adult's name, country and ID: what VFMS registers for each guest. Children are never registered. */
export function AdultGuestFields({
    id,
    names,
    errors,
    required = false,
}: {
    id: string;
    names: Record<Field, string>;
    errors: Record<string, string | undefined>;
    required?: boolean;
}) {
    const field = (key: Field) => ({
        id: `${id}-${key}`,
        name: names[key],
        required,
        'aria-invalid': Boolean(errors[key]),
        'aria-describedby': errors[key] ? `${id}-${key}-error` : undefined,
    });
    const error = (key: Field) => (
        <InputError id={`${id}-${key}-error`} message={errors[key]} />
    );
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2 sm:col-span-2">
                <Label htmlFor={`${id}-name`}>Full name</Label>
                <Input {...field('name')} maxLength={255} />
                {error('name')}
            </div>
            <div className="space-y-2 sm:col-span-2">
                <Label htmlFor={`${id}-nationality`}>Country</Label>
                <CountrySelect {...field('nationality')} />
                {error('nationality')}
            </div>
            <div className="space-y-2">
                <Label htmlFor={`${id}-document_type`}>ID document</Label>
                <NativeSelect {...field('document_type')} defaultValue="">
                    <option value="">
                        {required ? 'Choose document' : 'Add later'}
                    </option>
                    <option value="passport">Passport</option>
                    <option value="nida">NIDA</option>
                    <option value="zanid">ZanID</option>
                </NativeSelect>
                {error('document_type')}
            </div>
            <div className="space-y-2">
                <Label htmlFor={`${id}-document_number`}>Document number</Label>
                <Input
                    {...field('document_number')}
                    maxLength={255}
                    autoComplete="off"
                />
                {error('document_number')}
            </div>
        </div>
    );
}
