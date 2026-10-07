import { useState } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SelectOption } from '@/types';

export type RoleOption = SelectOption & { mayHaveAllProperties: boolean };

type Props = {
    /** Prefixes element IDs, so two sets of fields can share a page. */
    idPrefix: string;
    roleLabel?: string;
    roles: RoleOption[];
    properties: SelectOption[];
    defaultRole?: string;
    defaultAllProperties?: boolean;
    defaultPropertyIds?: string[];
    errors: Partial<Record<string, string>>;
};

/**
 * A membership's role and properties, posted as `role`, `all_properties` and
 * `property_ids[]` (ValidatesMembershipGrant on the server). Owners always
 * work in all properties.
 */
export function MembershipGrantFields({
    idPrefix,
    roleLabel = 'Role',
    roles,
    properties,
    defaultRole,
    defaultAllProperties = false,
    defaultPropertyIds = [],
    errors,
}: Props) {
    const [role, setRole] = useState<RoleOption | null>(
        roles.find((option) => option.value === defaultRole) ?? null,
    );
    const [allProperties, setAllProperties] = useState(defaultAllProperties);
    const isOwner = role?.value === 'owner';
    const grantsAll =
        isOwner || (allProperties && (role?.mayHaveAllProperties ?? false));

    return (
        <>
            <input
                type="hidden"
                name="all_properties"
                value={grantsAll ? '1' : '0'}
            />

            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-role`}>{roleLabel}</Label>
                <Select
                    name="role"
                    required
                    defaultValue={defaultRole}
                    onValueChange={(value) => {
                        const next =
                            roles.find((option) => option.value === value) ??
                            null;
                        setRole(next);

                        if (!next?.mayHaveAllProperties) {
                            setAllProperties(false);
                        }
                    }}
                >
                    <SelectTrigger id={`${idPrefix}-role`} className="w-full">
                        <SelectValue placeholder="Choose a role" />
                    </SelectTrigger>
                    <SelectContent>
                        {roles.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.role} />
            </div>

            <fieldset className="grid gap-3">
                <legend className="mb-2 text-sm font-medium">Properties</legend>
                {isOwner ? (
                    <p className="text-sm text-muted-foreground">
                        Owners work in all properties, including ones added
                        later.
                    </p>
                ) : (
                    <>
                        {role?.mayHaveAllProperties && (
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id={`${idPrefix}-all-properties`}
                                    checked={allProperties}
                                    onCheckedChange={(checked) =>
                                        setAllProperties(checked === true)
                                    }
                                />
                                <Label htmlFor={`${idPrefix}-all-properties`}>
                                    All properties, including ones added later
                                </Label>
                            </div>
                        )}
                        {!grantsAll &&
                            properties.map((property) => (
                                <div
                                    key={property.value}
                                    className="flex items-center gap-3"
                                >
                                    <Checkbox
                                        id={`${idPrefix}-property-${property.value}`}
                                        name="property_ids[]"
                                        value={property.value}
                                        defaultChecked={
                                            defaultPropertyIds.includes(
                                                property.value,
                                            ) || properties.length === 1
                                        }
                                    />
                                    <Label
                                        htmlFor={`${idPrefix}-property-${property.value}`}
                                    >
                                        {property.label}
                                    </Label>
                                </div>
                            ))}
                    </>
                )}
                <InputError
                    message={errors.property_ids ?? errors.all_properties}
                />
            </fieldset>
        </>
    );
}
