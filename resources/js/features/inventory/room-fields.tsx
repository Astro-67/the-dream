import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Field } from '@/features/inventory/fields';
import type { SelectOption } from '@/types';

type Errors = Partial<Record<string, string>>;

export function RoomTypeSelect({
    options,
    value,
    error,
}: {
    options: SelectOption[];
    value?: string;
    error?: string;
}) {
    return (
        <Field id="room_type_id" label="Room type" error={error}>
            <Select name="room_type_id" defaultValue={value} required>
                <SelectTrigger id="room_type_id" className="w-full">
                    <SelectValue placeholder="Choose a room type" />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

/** A room's number and floor. */
export function RoomFields({
    values,
    errors,
}: {
    values?: { number: string; floor: string | null };
    errors: Errors;
}) {
    return (
        <>
            <Field
                id="number"
                label="Room number"
                error={errors.number}
                hint="Unique in this property."
            >
                <Input
                    id="number"
                    name="number"
                    required
                    autoFocus
                    maxLength={16}
                    className="tabular-nums"
                    defaultValue={values?.number}
                />
            </Field>
            <Field id="floor" label="Floor (optional)" error={errors.floor}>
                <Input
                    id="floor"
                    name="floor"
                    maxLength={16}
                    defaultValue={values?.floor ?? ''}
                />
            </Field>
        </>
    );
}
