import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';

export type Settings = Record<
    string,
    string | number | boolean | string[] | null
>;
export type PropertySettings = {
    front_desk_settings: Settings;
    tax_settings: Settings;
    payment_settings: Settings;
};

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
    tax_inclusive: 'Prices include tax',
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
    values: PropertySettings;
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
                        {
                            {
                                front_desk_settings: 'Front desk',
                                tax_settings: 'Tax & ZRA',
                                payment_settings: 'Payments',
                            }[group]
                        }
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
