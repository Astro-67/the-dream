import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { NativeSelect } from '@/components/ui/select';

export function CountrySelect(props: ComponentProps<typeof NativeSelect>) {
    const { countries } = usePage<{
        countries: { code: string; name: string }[];
    }>().props;
    return (
        <NativeSelect {...props}>
            <option value="">Select country</option>
            {countries.map(({ code, name }) => (
                <option key={code} value={code}>
                    {name}
                </option>
            ))}
        </NativeSelect>
    );
}
