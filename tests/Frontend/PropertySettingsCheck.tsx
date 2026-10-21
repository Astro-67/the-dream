import assert from 'node:assert/strict';
import { App, Form } from '@inertiajs/react';
import { isValidElement, useState, type ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import EditProperty from '@/pages/properties/edit';
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

function settings(registration = true, errors?: Record<string, string>) {
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
                housekeeping_inspection_required: true,
                stayover_service_on_close: true,
                receipt_prefix: 'RC',
                close_time: '23:00',
                front_desk_settings: {
                    minimum_stay: 2,
                    walk_ins_enabled: true,
                },
                tax_settings: { tax_rate: 15 },
                payment_settings: {
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
console.log('Tabbed property settings and validation destinations: PASS');
