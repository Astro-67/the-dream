import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Link, usePage } from '@inertiajs/react';
import { formatDate } from '@/features/platform/format';
import { index as taxRules } from '@/routes/tax-rules';

export type Settings = Record<
    string,
    string | number | boolean | string[] | null
>;
export type PropertySettings = {
    front_desk_settings: Settings;
    tax_settings: Settings;
    payment_settings: Settings;
};

export const propertySettingsTabs: Record<string, string> = {
    hotel: 'Hotel',
    front_desk_settings: 'Front desk',
    tax_settings: 'Tax & ZRA',
    payment_settings: 'Payments',
    registration_fields: 'Guest registration',
};

export function propertySettingsTabForErrors(
    errors: Record<string, string | undefined>,
) {
    const errorTabs = Object.keys(errors).map((field) => {
        const group = field.split('.')[0];
        if (group in propertySettingsTabs) return group;
        if (field === 'receipt_prefix') return 'payment_settings';
        if (group === 'tax_inclusion') return 'tax_settings';
        if (
            [
                'housekeeping_inspection_required',
                'stayover_service_on_close',
                'close_time',
            ].includes(field)
        ) {
            return 'front_desk_settings';
        }
        return 'hotel';
    });
    return (
        Object.keys(propertySettingsTabs).find((tab) =>
            errorTabs.includes(tab),
        ) ?? 'hotel'
    );
}

const labels: Record<string, string> = {
    book_ahead_days: 'Book-ahead limit (days)',
    minimum_notice_hours: 'Minimum notice (hours)',
    same_day_cutoff_time: 'Same-day cutoff time (optional)',
    auto_cancel_unpaid_hours: 'Auto-cancel unpaid reservations after (hours)',
    free_cancellation_hours: 'Free cancellation window (hours)',
    default_check_in_time: 'Default check-in time',
    default_check_out_time: 'Default check-out time',
    walk_ins_enabled: 'Allow walk-ins',
    walk_in_requires_id: 'Require ID for walk-ins',
    maximum_overbooking_percentage: 'Maximum overbooking (%)',
    minimum_stay: 'Minimum stay (nights)',
    maximum_stay: 'Maximum stay (nights)',
    passport_required_for_foreign_guests:
        'Require passports for foreign guests',
    automatic_checkout_enabled: 'Enable automatic check-out',
    automatic_checkout_time: 'Automatic check-out time',
    automatic_checkout_include_unpaid:
        'Include unpaid folios in automatic check-out',
    automatic_checkout_include_overstays:
        'Include overstays in automatic check-out',
    automatic_checkout_summary_email: 'Automatic check-out summary email',
    zra_tax_category: 'ZRA tax category',
    tax_label: 'Tax label',
    tax_rate: 'Tax rate (%)',
    service_charge_enabled: 'Add service charge',
    service_charge_rate: 'Service charge (%)',
    show_breakdown_on_bill: 'Show tax breakdown on bill',
    show_breakdown_on_receipt: 'Show tax breakdown on receipt',
    accepted_methods: 'Accepted payment methods',
    block_checkout_if_unpaid: 'Block check-out if unpaid',
    manager_override: 'Allow manager override for unpaid check-out',
    deposit_percentage: 'Deposit (%)',
    payment_due_days: 'Payment due (days)',
};

const methods: Record<string, string> = {
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    mobile_money: 'Mobile money',
    card: 'Card',
    channel_collected: 'Channel-collected amount',
};

export function PropertySettingsFields({
    values,
    errors,
}: {
    values: Partial<PropertySettings>;
    errors: Record<string, string | undefined>;
}) {
    return (
        <>
            {Object.entries(values).map(([group, fields]) => (
                <fieldset
                    key={group}
                    className="grid gap-4 border-t pt-6 sm:grid-cols-2"
                >
                    <legend className="px-1 text-lg font-semibold">
                        {propertySettingsTabs[group]}
                    </legend>
                    {group === 'tax_settings' && (
                        <p className="text-sm text-muted-foreground sm:col-span-2">
                            Tax settings are estimates before VFMS. ZRA assesses
                            the infrastructure levy at check-out. Tax rule
                            changes are managed under Taxes and service charges.
                        </p>
                    )}
                    {Object.entries(fields).map(([field, value]) => {
                        const key = `${group}.${field}`;
                        const name = `${group}[${field}]`;
                        const error = errors[key] ?? errors[`${key}.0`];
                        return (
                            <div
                                key={field}
                                className="grid content-start gap-2"
                            >
                                <Label htmlFor={key}>{labels[field]}</Label>
                                {Array.isArray(value) ? (
                                    <div id={key} className="grid gap-3">
                                        {Object.entries(methods).map(
                                            ([method, label]) => (
                                                <div
                                                    key={method}
                                                    className="flex items-center gap-2"
                                                >
                                                    <Checkbox
                                                        id={`${key}-${method}`}
                                                        name={`${name}[]`}
                                                        value={method}
                                                        defaultChecked={value.includes(
                                                            method,
                                                        )}
                                                    />
                                                    <Label
                                                        htmlFor={`${key}-${method}`}
                                                    >
                                                        {label}
                                                    </Label>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                ) : typeof value === 'boolean' ? (
                                    <NativeSelect
                                        id={key}
                                        name={name}
                                        defaultValue={value ? '1' : '0'}
                                        aria-invalid={!!error}
                                    >
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </NativeSelect>
                                ) : field === 'zra_tax_category' ? (
                                    <NativeSelect
                                        id={key}
                                        name={name}
                                        defaultValue={String(value)}
                                        aria-invalid={!!error}
                                    >
                                        <option value="vat">VAT</option>
                                        <option value="hotel_levy">
                                            Hotel levy
                                        </option>
                                        <option value="tour_operator">
                                            Tour operator
                                        </option>
                                        <option value="exempt">Exempt</option>
                                    </NativeSelect>
                                ) : (
                                    <Input
                                        id={key}
                                        name={name}
                                        defaultValue={value ?? ''}
                                        type={
                                            field.endsWith('_time')
                                                ? 'time'
                                                : field.endsWith('_email')
                                                  ? 'email'
                                                  : typeof value === 'number'
                                                    ? 'number'
                                                    : 'text'
                                        }
                                        min={
                                            field === 'minimum_stay' ||
                                            field === 'maximum_stay'
                                                ? 1
                                                : 0
                                        }
                                        max={
                                            field.includes('percentage') ||
                                            field.endsWith('_rate')
                                                ? 100
                                                : undefined
                                        }
                                        step={
                                            field.includes('percentage') ||
                                            field.endsWith('_rate')
                                                ? '0.01'
                                                : undefined
                                        }
                                        aria-invalid={!!error}
                                    />
                                )}
                                <InputError message={error} />
                            </div>
                        );
                    })}
                </fieldset>
            ))}
        </>
    );
}

export type TaxInclusionControl = {
    state: 'included' | 'added' | 'mixed' | 'none';
    covers_extras: boolean;
    earliest: string;
    pending: { name: string; inclusive: boolean; effective_from: string }[];
};

const inclusionLabels: Record<string, string> = {
    vat: 'VAT included in room rate',
    levy: 'Infrastructure levy included in room rate',
};

/** Reads and changes the tax rules themselves; nothing here is stored as a property setting. */
export function TaxInclusionFields({
    propertyId,
    controls,
    canManage,
    errors,
}: {
    propertyId: string;
    controls: Record<string, TaxInclusionControl>;
    canManage: boolean;
    errors: Record<string, string | undefined>;
}) {
    // The taxes page works on the selected property, which may not be the one edited here.
    const selected =
        usePage<{ tenant: { property?: { id: string } | null } }>().props.tenant
            .property?.id === propertyId;
    const earliest = Object.values(controls)
        .filter((control) => control.state !== 'none')
        .map((control) => control.earliest)
        .sort()
        .at(-1);
    return (
        <fieldset className="grid gap-4 border-t pt-6 sm:grid-cols-2">
            <legend className="px-1 text-lg font-semibold">
                Taxes in the room rate
            </legend>
            <p className="text-sm text-muted-foreground sm:col-span-2">
                These choices change the tax rules from a date. Earlier dates
                and posted charges keep their tax.
            </p>
            {Object.entries(controls).map(([control, value]) => {
                const key = `tax_inclusion.${control}`;
                return (
                    <div key={control} className="grid content-start gap-2">
                        <Label htmlFor={key}>{inclusionLabels[control]}</Label>
                        {value.state === 'none' ? (
                            <p className="text-sm">
                                No rule covers room charges.{' '}
                                {selected ? (
                                    <Link
                                        href={taxRules()}
                                        className="underline"
                                    >
                                        Configure taxes
                                    </Link>
                                ) : (
                                    'Switch to this property to configure taxes.'
                                )}
                            </p>
                        ) : (
                            <NativeSelect
                                id={key}
                                name={`tax_inclusion[${control}]`}
                                defaultValue={
                                    value.state === 'mixed'
                                        ? ''
                                        : value.state === 'included'
                                          ? '1'
                                          : '0'
                                }
                                disabled={!canManage}
                                aria-invalid={!!errors[key]}
                            >
                                {value.state === 'mixed' && (
                                    <option value="">
                                        Mixed: leave rules as they are
                                    </option>
                                )}
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </NativeSelect>
                        )}
                        {value.covers_extras && (
                            <p className="text-sm text-muted-foreground">
                                This rule also covers extras. An extra entered
                                as a VAT-inclusive price stays inclusive.
                            </p>
                        )}
                        {value.pending.map((rule) => (
                            <p
                                key={`${rule.name}-${rule.effective_from}`}
                                className="text-sm text-muted-foreground"
                            >
                                From {formatDate(rule.effective_from)}:{' '}
                                {rule.name}{' '}
                                {rule.inclusive ? 'included' : 'added'}
                            </p>
                        ))}
                        <InputError message={errors[key]} />
                    </div>
                );
            })}
            {earliest && canManage && (
                <div className="grid content-start gap-2">
                    <Label htmlFor="tax_inclusion.effective_from">
                        Change takes effect from
                    </Label>
                    <Input
                        id="tax_inclusion.effective_from"
                        name="tax_inclusion[effective_from]"
                        type="date"
                        min={earliest}
                        defaultValue={earliest}
                        aria-describedby="tax_inclusion_earliest"
                        aria-invalid={!!errors['tax_inclusion.effective_from']}
                    />
                    <p
                        id="tax_inclusion_earliest"
                        className="text-sm text-muted-foreground"
                    >
                        Earliest: {formatDate(earliest)}
                    </p>
                    <InputError
                        message={errors['tax_inclusion.effective_from']}
                    />
                </div>
            )}
        </fieldset>
    );
}
