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
    VfmsFields,
    PropertySettingsFields,
    propertySettingsTabForErrors,
    propertySettingsTabs,
} from '@/features/tenancy/property-settings-fields';

const paymentSettings = renderToStaticMarkup(
    <PropertySettingsFields
        values={{ payment_settings: { accepted_methods: ['other'] } }}
        errors={{}}
    />,
);
assert.match(paymentSettings, /checked="" value="other"/);
assert.match(
    paymentSettings,
    /for="payment_settings\.accepted_methods-other">Other<\/label>/,
);

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
            },
            canManageTaxes: true,
            canManageCashierShifts,
            vfms: null,
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
    goLiveRequested: false,
    goLiveDeclinedReason: null,
    canGoLive: false,
    canReviewTaxes: false,
    receiptNotice: null,
};
const checklistHtml = renderToStaticMarkup(
    <OnboardingChecklist checklist={checklist} />,
);
const declinedChecklist = renderToStaticMarkup(
    <OnboardingChecklist
        checklist={{
            ...checklist,
            goLiveDeclinedReason: 'Correct the business address.',
        }}
    />,
);
assert.match(declinedChecklist, /Go-live request declined/);
assert.match(declinedChecklist, /Correct the business address\./);
assert.match(declinedChecklist, /request go-live again/);
assert.doesNotMatch(checklistHtml, /Go-live request declined/);
for (const [roomTypeDone, roomDone] of [
    [false, false],
    [true, false],
    [true, true],
]) {
    const roomsHtml = renderToStaticMarkup(
        <OnboardingChecklist
            checklist={{
                ...checklist,
                items: [
                    {
                        key: 'rooms',
                        label: 'Configure rooms',
                        completed: roomTypeDone && roomDone,
                        href: '/rooms',
                        items: [
                            {
                                key: 'room_type',
                                label: 'Add a room type',
                                completed: roomTypeDone,
                                href: '/room-types/create',
                            },
                            {
                                key: 'room',
                                label: 'Add rooms',
                                completed: roomDone,
                                href: '/rooms/create',
                            },
                        ],
                    },
                ],
            }}
        />,
    );
    assert.match(
        roomsHtml,
        /href="\/room-types\/create"[^>]*>Add a room type<\/a>/,
    );
    assert.match(roomsHtml, /href="\/rooms\/create"[^>]*>Add rooms<\/a>/);
    assert.equal(
        (roomsHtml.match(/>Complete<\/span>/g) ?? []).length,
        Number(roomTypeDone) +
            Number(roomDone) +
            Number(roomTypeDone && roomDone),
    );
    assert.match(roomsHtml, new RegExp(`${roomDone ? 1 : 0} of 1 completed`));
}
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
// The removed generic switch never renders; the change date starts at VAT's earliest date.
assert.doesNotMatch(html, /tax_inclusive|Prices include tax/);
assert.match(html, /min="2026-12-11"/);
assert.match(html, /This rule also covers extras/);
// Mixed rules offer to leave them alone; no rule offers a link, not a fake switch.
assert.match(
    settings(true, undefined, 'mixed'),
    /<option value="" selected="">Mixed: leave rules as they are/,
);
const none = settings(true, undefined, 'none');
assert.doesNotMatch(none, /name="tax_inclusion\[vat\]"/);
assert.match(none, /href="\/tax-rules"[^>]*>Configure taxes/);

function vfmsSection(vfms: Partial<Parameters<typeof VfmsFields>[0]['vfms']>) {
    const component = () => (
        <VfmsFields
            propertyId="hotel"
            timeZone="Africa/Dar_es_Salaam"
            vfms={{
                integration_id: null,
                registration_id: null,
                token_saved: false,
                mode: 'sandbox',
                live_since: null,
                credentials_refused_at: null,
                zra_check: null,
                zra_checked_at: null,
                confirmation: 'LIVE',
                ...vfms,
            }}
        />
    );
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
// A property with nothing saved: Sandbox, token required, Live disabled until all three are saved.
const fresh = vfmsSection({});
assert.match(fresh, />Sandbox</);
assert.match(fresh, /No token saved yet/);
assert.match(fresh, /type="password"[^>]*id="vfms_token" required=""/);
assert.match(fresh, /<button[^>]*disabled=""[^>]*>Switch to Live/);
assert.match(fresh, /Not checked with ZRA yet\./);
assert.match(fresh, /<button[^>]*disabled=""[^>]*>Check with ZRA/);
// Saved: the token field is blank and optional, never prefilled; Live asks for the typed word.
const saved = vfmsSection({
    integration_id: 'INT-1',
    registration_id: 'REG-1',
    token_saved: true,
});
assert.match(saved, /Token saved\. Leave blank to keep it\./);
assert.doesNotMatch(saved, /id="vfms_token"[^>]*(required|value=)/);
assert.match(saved, /Type LIVE to confirm/);
assert.doesNotMatch(saved, /<button[^>]*disabled=""[^>]*>Switch to Live/);
// Live: switching back needs only a reason; refused credentials are flagged.
const live = vfmsSection({
    integration_id: 'INT-1',
    registration_id: 'REG-1',
    token_saved: true,
    mode: 'live',
    credentials_refused_at: '2026-10-24T08:00:00Z',
});
assert.match(live, />Live</);
assert.match(live, /Credentials refused/);
assert.match(live, /name="mode" value="sandbox"/);
// The last ZRA check shows its outcome and the time in the property's timezone.
for (const [zra_check, label] of [
    ['accepted', 'Accepted by ZRA'],
    ['refused', 'Refused by ZRA'],
    ['unreachable', 'Could not reach ZRA'],
] as const) {
    const checked = vfmsSection({
        integration_id: 'INT-1',
        registration_id: 'REG-1',
        token_saved: true,
        zra_check,
        zra_checked_at: '2026-10-31T09:05:00Z',
    });
    assert.match(checked, new RegExp(`>${label}<`));
    assert.match(checked, /31 Oct 2026 12:05/);
    assert.doesNotMatch(checked, /<button[^>]*disabled=""[^>]*>Check with ZRA/);
}
assert.doesNotMatch(live, /name="confirmation"/);
// The section shows only when the server sends it (VFMS properties the member manages).
assert.doesNotMatch(html, /Save VFMS credentials/);
console.log('Tabbed property settings and validation destinations: PASS');
