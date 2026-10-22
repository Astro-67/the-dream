import assert from 'node:assert/strict';
import { App, Form } from '@inertiajs/react';
import { isValidElement, useState, type ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import EditProperty from '@/pages/properties/edit';
import CreateProperty from '@/pages/properties/create';
import CreateOrganization from '@/pages/organizations/create';
import { PropertyFields } from '@/features/tenancy/property-fields';
import { OnboardingChecklist } from '@/features/tenancy/onboarding-checklist';
import {
    propertySettingsTabForErrors,
    propertySettingsTabs,
} from '@/features/tenancy/property-settings-fields';

function returnValidationErrors(
    node: ReactNode,
    errors: Record<string, string>,
): boolean {
    if (Array.isArray(node)) {
        return node.some((child) => returnValidationErrors(child, errors));
    }
    if (
        !isValidElement<{
            children?: ReactNode;
            onError?: (errors: Record<string, string>) => void;
        }>(node)
    )
        return false;
    if (node.type === Form) {
        assert.ok(node.props.onError);
        node.props.onError(errors);
        return true;
    }
    return returnValidationErrors(node.props.children, errors);
}

function settings(
    registration = true,
    errors?: Record<string, string>,
    vatState: 'included' | 'mixed' | 'none' = 'included',
    canManageCashierShifts = true,
) {
    const component = () => {
        const [returnedErrors, setReturnedErrors] = useState(false);
        const page = EditProperty({
            property: {
                id: 'hotel',
                name: 'Sample hotel',
                property_type: 'hotel',
                address_line: 'Beach road',
                city: 'Zanzibar',
                region: 'Urban West',
                postal_code: null,
                phone: '+255777000000',
                email: 'hotel@example.test',
                timezone: 'Africa/Dar_es_Salaam',
                currency: 'USD',
                jurisdiction_profile: 'zanzibar',
                tin: 'TIN-TEST',
                z_number: 'Z-TEST/LOCATION',
                vrn: 'VRN-TEST',
                zra_hotel_code: 'HOTEL-TEST',
                housekeeping_inspection_required: true,
                stayover_service_on_close: true,
                receipt_prefix: 'RC',
                close_time: '23:00',
                front_desk_settings: {
                    minimum_stay: 2,
                    walk_ins_enabled: true,
                    automatic_checkout_include_unpaid: true,
                },
                tax_settings: { tax_rate: 15 },
                payment_settings: {
                    use_cashier_shifts: true,
                    accepted_methods: ['cash'],
                    deposit_percentage: 20,
                },
                ...(registration ? { registration_fields: ['number'] } : {}),
            },
            propertyTypes: [{ value: 'hotel', label: 'Hotel' }],
            currencies: [{ value: 'USD', label: 'USD' }],
            jurisdictionProfiles: [{ value: 'zanzibar', label: 'Zanzibar' }],
            timezones: ['Africa/Dar_es_Salaam'],
            registrationFieldOptions: ['number', 'nationality'],
            taxInclusion: {
                vat: {
                    state: vatState,
                    covers_extras: true,
                    earliest: '2026-12-11',
                    pending: [],
                },
                levy: {
                    state: 'added',
                    covers_extras: false,
                    earliest: '2026-12-12',
                    pending: [],
                },
            },
            canManageTaxes: true,
            canManageCashierShifts,
        });
        if (errors && !returnedErrors) {
            assert.ok(returnValidationErrors(page, errors));
            setReturnedErrors(true);
        }
        return page;
    };
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'properties/edit',
                url: '/properties/hotel/edit',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: { errors: {}, tenant: { property: { id: 'hotel' } } },
            }}
            initialComponent={component}
            resolveComponent={async () => component}
        />,
    );
}

const html = settings();
assert.match(html, />Use cashier shifts</);
assert.match(
    settings(true, undefined, 'included', false),
    /name="payment_settings\[use_cashier_shifts\]"[^>]*disabled=""/,
);
assert.match(html, /If enabled, automatic check-out includes unpaid stays/);
assert.match(html, /Their balances remain open after departure/);
assert.match(
    html,
    /name="front_desk_settings\[automatic_checkout_include_unpaid\]"[^>]*aria-describedby="front_desk_settings.automatic_checkout_include_unpaid-hint"/,
);
assert.match(html, /<option value="1" selected="">Yes<\/option>/);
assert.match(html, /<legend[^>]*>Tax &amp; regulatory information<\/legend>/);
assert.doesNotMatch(html, /<details|ZRA tax registration \(optional\)/);
for (const [field, value] of [
    ['tin', 'TIN-TEST'],
    ['z_number', 'Z-TEST/LOCATION'],
    ['vrn', 'VRN-TEST'],
    ['zra_hotel_code', 'HOTEL-TEST'],
]) {
    assert.match(html, new RegExp(`id="${field}"[^>]*value="${value}"`));
}
const options = {
    propertyTypes: [{ value: 'hotel', label: 'Hotel' }],
    currencies: [{ value: 'USD', label: 'USD' }],
    jurisdictionProfiles: [{ value: 'zanzibar', label: 'Zanzibar' }],
    timezones: ['Africa/Dar_es_Salaam'],
};
for (const component of [
    () => (
        <CreateProperty
            {...options}
            defaults={{ timezone: 'Africa/Dar_es_Salaam', currency: 'USD' }}
        />
    ),
    () => (
        <CreateOrganization
            {...options}
            countries={[{ value: 'TZ', label: 'Tanzania' }]}
            defaults={{
                country: 'TZ',
                timezone: 'Africa/Dar_es_Salaam',
                currency: 'USD',
            }}
        />
    ),
]) {
    const page = renderToStaticMarkup(
        <App
            initialPage={{
                component: 'setup',
                url: '/setup',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: { errors: {}, tenant: { property: { id: 'hotel' } } },
            }}
            initialComponent={component}
            resolveComponent={async () => component}
        />,
    );
    assert.match(page, /Tax &amp; regulatory information/);
    assert.doesNotMatch(page, /<details/);
    for (const field of ['tin', 'vrn', 'z_number']) {
        assert.match(page, new RegExp(`name="${field}"`));
        assert.doesNotMatch(
            page,
            new RegExp(`<input[^>]*name="${field}"[^>]*required`),
        );
    }
}
const mainland = renderToStaticMarkup(
    <PropertyFields
        options={options}
        values={{ jurisdiction_profile: 'mainland_tanzania' }}
        errors={{}}
    />,
);
assert.doesNotMatch(mainland, /name="zra_hotel_code"/);
assert.match(mainland, /Optional for mainland properties/);
assert.match(html, /Required before go-live in Zanzibar/);
assert.match(html, /VAT-category properties only/);

const checklist = {
    propertyId: 'hotel',
    propertyName: 'Sample hotel',
    items: [
        {
            key: 'profile',
            label: 'Property profile',
            completed: false,
            href: null,
        },
    ],
    gates: [
        {
            key: 'tin',
            label: 'TIN',
            passed: false,
            href: '/properties/hotel/edit#tin',
        },
    ],
    canGoLive: false,
    canReviewTaxes: false,
    receiptNotice: null,
};
const checklistHtml = renderToStaticMarkup(
    <OnboardingChecklist checklist={checklist} />,
);
assert.match(
    checklistHtml,
    /href="\/properties\/hotel\/edit#tin"[^>]*>TIN<\/a>/,
);
assert.doesNotMatch(
    renderToStaticMarkup(
        <OnboardingChecklist
            checklist={{
                ...checklist,
                gates: [{ ...checklist.gates[0], href: null }],
            }}
        />,
    ),
    /href=/,
);
assert.equal((html.match(/<form\b/g) ?? []).length, 1);
assert.equal((html.match(/role="tab"/g) ?? []).length, 5);
assert.equal((html.match(/role="tabpanel"/g) ?? []).length, 5);
for (const [tab, label] of Object.entries(propertySettingsTabs)) {
    assert.ok(html.includes(`aria-controls="${tab}"`));
    assert.ok(html.includes(`>${label.replace('&', '&amp;')}</button>`));
    assert.match(
        html,
        new RegExp(
            `id="${tab}" role="tabpanel" aria-labelledby="tab-${tab}"${tab === 'hotel' ? '' : ' hidden=""'}`,
        ),
    );
}
for (const name of [
    '_property',
    'name',
    'housekeeping_inspection_required',
    'stayover_service_on_close',
    'close_time',
    'receipt_prefix',
    'front_desk_settings[minimum_stay]',
    'tax_settings[tax_rate]',
    'payment_settings[deposit_percentage]',
    'registration_fields[]',
    'tax_inclusion[vat]',
    'tax_inclusion[levy]',
    'tax_inclusion[effective_from]',
]) {
    assert.ok(html.includes(`name="${name}"`), `${name} must stay mounted`);
}
assert.equal((html.match(/>Save changes<\/button>/g) ?? []).length, 1);
assert.doesNotMatch(
    settings(false),
    /role="tab"[^>]*aria-controls="registration_fields"/,
);
assert.doesNotMatch(settings(false), /name="registration_fields\[\]"/);

for (const [field, expected] of [
    ['name', 'hotel'],
    ['tin', 'hotel'],
    ['housekeeping_inspection_required', 'front_desk_settings'],
    ['stayover_service_on_close', 'front_desk_settings'],
    ['close_time', 'front_desk_settings'],
    ['front_desk_settings.minimum_stay', 'front_desk_settings'],
    ['tax_settings.tax_rate', 'tax_settings'],
    ['receipt_prefix', 'payment_settings'],
    ['payment_settings.accepted_methods.0', 'payment_settings'],
    ['registration_fields.0', 'registration_fields'],
    ['tax_inclusion.effective_from', 'tax_settings'],
]) {
    assert.equal(
        propertySettingsTabForErrors({ [field]: 'Invalid' }),
        expected,
    );
}
assert.equal(
    propertySettingsTabForErrors({
        'payment_settings.deposit_percentage': 'Invalid',
        'tax_settings.tax_rate': 'Invalid',
        close_time: 'Invalid',
    }),
    'front_desk_settings',
);
assert.equal(propertySettingsTabForErrors({}), 'hotel');
const invalid = settings(true, {
    'payment_settings.deposit_percentage': 'Invalid',
    'tax_settings.tax_rate': 'Invalid',
});
assert.match(invalid, /id="tab-tax_settings"[^>]*aria-selected="true"/);
assert.match(
    invalid,
    /id="tax_settings" role="tabpanel" aria-labelledby="tab-tax_settings" tabindex="0"/,
);
assert.match(
    invalid,
    /id="hotel" role="tabpanel" aria-labelledby="tab-hotel" hidden=""/,
);
// The removed generic switch never renders; the change date starts at the latest earliest date.
assert.doesNotMatch(html, /tax_inclusive|Prices include tax/);
assert.match(html, /min="2026-12-12"/);
assert.match(html, /This rule also covers extras/);
// Mixed rules offer to leave them alone; no rule offers a link, not a fake switch.
assert.match(
    settings(true, undefined, 'mixed'),
    /<option value="" selected="">Mixed: leave rules as they are/,
);
const none = settings(true, undefined, 'none');
assert.doesNotMatch(none, /name="tax_inclusion\[vat\]"/);
assert.match(none, /href="\/tax-rules"[^>]*>Configure taxes/);
console.log('Tabbed property settings and validation destinations: PASS');
