import assert from 'node:assert/strict';
import { isValidElement, useState, type ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import Create from '@/pages/reservations/create';

type FieldProps = {
    id?: string;
    name?: string;
    type?: string;
    required?: boolean;
    value?: string | number;
    defaultValue?: string | number;
    onChange?: (event: { target: { value: string } }) => void;
    children?: ReactNode | ((props: object) => ReactNode);
};

function fieldsIn(node: ReactNode): FieldProps[] {
    if (Array.isArray(node)) return node.flatMap(fieldsIn);
    if (!isValidElement<FieldProps>(node)) return [];
    const children = node.props.children;
    return [
        ...(node.props.name || node.props.id ? [node.props] : []),
        ...fieldsIn(
            typeof children === 'function'
                ? children({ errors: {}, processing: false })
                : children,
        ),
    ];
}

function formAfter(
    edits: [string, string][],
    source = 'direct',
    filesWithVfms = true,
) {
    function Interaction() {
        const [step, setStep] = useState(0);
        const fields = fieldsIn(
            Create({
                stay: {
                    room_type_id: 'type',
                    rate_plan_id: 'plan',
                    arrival: '2026-12-10',
                    departure: '2026-12-12',
                    adults: 2,
                    children: 0,
                    source,
                },
                roomType: { id: 'type', name: 'Double' },
                ratePlan: { id: 'plan', name: 'Standard', currency: 'USD' },
                ratePlans: [
                    {
                        id: 'plan',
                        name: 'Standard',
                        currency: 'USD',
                        room_type_ids: ['type'],
                    },
                ],
                rooms: [],
                restrictionWarnings: [],
                nightlyRate: 10000,
                businessDate: '2026-12-01',
                filesWithVfms,
            }),
        );
        if (step < edits.length) {
            const [name, value] = edits[step];
            const field = fields.find((field) => field.name === name);
            assert.ok(field, `Missing ${name} field`);
            field.onChange?.({ target: { value } });
            setStep(step + 1);
        }
        return fields.map((field) => (
            <input
                key={field.name ?? field.id}
                id={field.id}
                name={field.name}
                type={field.type}
                required={field.required}
                value={field.value ?? field.defaultValue ?? ''}
                readOnly
            />
        ));
    }
    return renderToStaticMarkup(<Interaction />);
}

const edited = formAfter([
    ['arrival', '2026-12-15'],
    ['departure', '2026-12-18'],
    ['adults', '3'],
    ['children', '1'],
    ['rooms', '2'],
    ['source', 'phone'],
    ['source', 'whatsapp'],
]);
for (const [name, value] of [
    ['arrival', '2026-12-15'],
    ['departure', '2026-12-18'],
    ['adults', '3'],
    ['children', '1'],
    ['rooms', '2'],
]) {
    assert.match(edited, new RegExp(`name="${name}"[^>]*value="${value}"`));
}
assert.match(
    formAfter([
        ['arrival', '2026-12-15'],
        ['source', 'walk-in'],
        ['source', 'phone'],
    ]),
    /name="arrival"[^>]*value="2026-12-01"/,
);
assert.match(formAfter([], 'walk-in'), /name="arrival"[^>]*value="2026-12-01"/);
console.log('Reservation stay edits and walk-in source changes: PASS');
const guestForm = formAfter([]);
assert.match(guestForm, /name="distribute_guests"[^>]*value="1"/);
assert.doesNotMatch(guestForm, /<input[^>]*id="walk-in"/);
console.log('Walk-in is chosen through Source, not a checkbox: PASS');
for (const [name, type] of [
    ['first_name', 'text'],
    ['last_name', 'text'],
    ['email', 'email'],
    ['phone', 'tel'],
]) {
    assert.match(
        guestForm,
        new RegExp(`type="${type}"[^>]*name="lead_guest\\[${name}\\]"`),
    );
}
assert.match(guestForm, /required=""[^>]*name="lead_guest\[first_name\]"/);
assert.match(
    guestForm,
    /<input[^>]*type="email" readOnly="" name="lead_guest\[email\]"/,
);
console.log('Lead guest fields take text, email and phone: PASS');
// Two adults booked: one optional section for the second adult, none for children.
assert.match(guestForm, /id="guest-0"/);
assert.doesNotMatch(guestForm, /id="guest-1"/);
console.log('Each extra adult gets an optional guest section: PASS');
assert.match(guestForm, /name="paying_institution_type"[^>]*value=""/);
assert.doesNotMatch(guestForm, /name="paying_institution_name"/);
const institutionForm = formAfter([['paying_institution_type', 'government']]);
assert.match(institutionForm, /required=""[^>]*name="paying_institution_name"/);
assert.match(
    institutionForm,
    /required=""[^>]*name="paying_institution_zrb_number"/,
);
assert.doesNotMatch(
    formAfter([], 'direct', false),
    /name="paying_institution_type"/,
);
console.log('Paying institution appears only where VFMS filing applies: PASS');
