import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type RoomTypeValues = {
    id: string;
    name: string;
    code: string;
    description: string | null;
    base_occupancy: number;
    max_adults: number;
    max_children: number;
    max_occupancy: number;
};

type Errors = Partial<Record<string, string>>;

export function Field({
    id,
    label,
    error,
    hint,
    className,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

function CountField({
    name,
    label,
    min,
    value,
    error,
}: {
    name: keyof RoomTypeValues;
    label: string;
    min: number;
    value?: number;
    error?: string;
}) {
    return (
        <Field id={name} label={label} error={error}>
            <Input
                id={name}
                name={name}
                type="number"
                inputMode="numeric"
                min={min}
                max={50}
                required
                defaultValue={value}
                className="tabular-nums"
            />
        </Field>
    );
}

/** The room type form's fields: name, code, description and occupancy limits. */
export function RoomTypeFields({
    values,
    errors,
}: {
    values?: Partial<RoomTypeValues>;
    errors: Errors;
}) {
    return (
        <div className="grid gap-6 sm:grid-cols-2">
            <Field id="name" label="Name" error={errors.name}>
                <Input
                    id="name"
                    name="name"
                    required
                    autoFocus
                    maxLength={255}
                    defaultValue={values?.name}
                />
            </Field>
            <Field
                id="code"
                label="Code"
                error={errors.code}
                hint="Short and unique in this property, such as DLX."
            >
                <Input
                    id="code"
                    name="code"
                    required
                    maxLength={16}
                    className="font-mono uppercase"
                    defaultValue={values?.code}
                />
            </Field>
            <Field
                id="description"
                label="Description (optional)"
                error={errors.description}
                className="sm:col-span-2"
            >
                <textarea
                    id="description"
                    name="description"
                    rows={3}
                    maxLength={2000}
                    defaultValue={values?.description ?? ''}
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                />
            </Field>
            <CountField
                name="base_occupancy"
                label="Base occupancy"
                min={1}
                value={values?.base_occupancy}
                error={errors.base_occupancy}
            />
            <CountField
                name="max_occupancy"
                label="Maximum guests"
                min={1}
                value={values?.max_occupancy}
                error={errors.max_occupancy}
            />
            <CountField
                name="max_adults"
                label="Maximum adults"
                min={1}
                value={values?.max_adults}
                error={errors.max_adults}
            />
            <CountField
                name="max_children"
                label="Maximum children"
                min={0}
                value={values?.max_children}
                error={errors.max_children}
            />
        </div>
    );
}
