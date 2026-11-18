import assert from 'node:assert/strict';
import { App, Form } from '@inertiajs/react';
import {
    Children,
    isValidElement,
    useState,
    type ComponentProps,
    type FormEvent,
    type ReactNode,
} from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { TooltipProvider } from '@/components/ui/tooltip';
import CreateOrganization from '@/pages/organizations/create';
import Import from '@/pages/onboarding/import';

function render(content: () => ReactNode) {
    return renderToStaticMarkup(
        <TooltipProvider>
            <App
                initialPage={{
                    component: 'organizations/create',
                    url: '/organizations/create',
                    version: null,
                    clearHistory: false,
                    encryptHistory: false,
                    props: {
                        name: 'Karafuu PMS',
                        errors: {},
                        tenant: { property: { id: 'property-1' } },
                    },
                }}
                initialComponent={content}
                resolveComponent={async () => content}
            />
        </TooltipProvider>,
    );
}

function setup(actions: ((form: ComponentProps<typeof Form>) => void)[] = []) {
    return render(() => {
        const [action, setAction] = useState(0);
        const page = CreateOrganization({
            countries: [{ value: 'TZ', label: 'Tanzania' }],
            propertyTypes: [{ value: 'hotel', label: 'Hotel' }],
            currencies: [{ value: 'TZS', label: 'TZS' }],
            jurisdictionProfiles: [{ value: 'zanzibar', label: 'Zanzibar' }],
            timezones: ['Africa/Dar_es_Salaam'],
            defaults: {
                country: 'TZ',
                currency: 'TZS',
                timezone: 'Africa/Dar_es_Salaam',
            },
        });
        if (action < actions.length) {
            const form = Children.toArray(page.props.children).find(
                (child) => isValidElement(child) && child.type === Form,
            );
            assert.ok(isValidElement<ComponentProps<typeof Form>>(form));
            actions[action](form.props);
            setAction(action + 1);
        }
        return page;
    });
}

function submit(
    form: ComponentProps<typeof Form>,
    invalid: { business: boolean } | null = null,
) {
    let prevented = false;
    let stopped = false;
    let reported = false;
    assert.ok(form.onSubmitCapture);
    form.onSubmitCapture({
        currentTarget: {
            querySelector: () =>
                invalid && {
                    closest: () => (invalid.business ? {} : null),
                    reportValidity: () => {
                        reported = true;
                        return false;
                    },
                },
        },
        preventDefault: () => {
            prevented = true;
        },
        stopPropagation: () => {
            stopped = true;
        },
    } as unknown as FormEvent<HTMLFormElement>);
    return { prevented, stopped, reported };
}

const initial = setup();
assert.match(
    initial,
    /aria-current="step"><span aria-hidden="true">3<\/span>Business/,
);
assert.match(initial, /A home for your business/);
assert.match(initial, /Continue to your property/);
assert.match(initial, /fieldset hidden=""/);
assert.match(initial, /name="tin"/);
assert.match(initial, /name="property_name"/);
assert.match(initial, /Theme: System/);
assert.doesNotMatch(
    initial,
    /auth-photo|data-test="create-organization-button"/,
);
assert.equal((initial.match(/<form\b/g) ?? []).length, 1);

const property = setup([
    (form) =>
        assert.deepEqual(submit(form), {
            prevented: true,
            stopped: true,
            reported: false,
        }),
    (form) =>
        assert.deepEqual(submit(form), {
            prevented: false,
            stopped: false,
            reported: false,
        }),
]);
assert.match(
    property,
    /aria-current="step"><span aria-hidden="true">4<\/span>Property/,
);
assert.match(property, /Meet your first property/);
assert.match(property, /Back to business/);
assert.match(property, /data-test="create-organization-button"/);
assert.match(property, /<fieldset data-business="true" hidden=""/);
assert.match(property, /name="organization_name"/);
assert.match(property, /created together/);

for (const [field, step] of [
    ['organization_name', 'Business'],
    ['rooms', 'Business'],
    ['country', 'Business'],
    ['property_name', 'Property'],
    ['jurisdiction_profile', 'Property'],
] as const) {
    const failed = setup([
        (form) => {
            assert.ok(form.onError);
            form.onError({ [field]: 'Please correct this field.' });
        },
    ]);
    assert.match(
        failed,
        new RegExp(
            `aria-current="step"><span aria-hidden="true">[34]</span>${step}`,
        ),
    );
}

const frame = Object.getOwnPropertyDescriptor(
    globalThis,
    'requestAnimationFrame',
);
try {
    Object.defineProperty(globalThis, 'requestAnimationFrame', {
        configurable: true,
        value: (callback: FrameRequestCallback) => {
            callback(0);
            return 1;
        },
    });
    for (const business of [true, false]) {
        const failed = setup([
            (form) => {
                form.onError?.({ property_name: 'Required' });
            },
            (form) =>
                assert.deepEqual(submit(form, { business }), {
                    prevented: true,
                    stopped: true,
                    reported: true,
                }),
        ]);
        assert.match(
            failed,
            new RegExp(
                `aria-current="step"><span aria-hidden="true">[34]</span>${business ? 'Business' : 'Property'}`,
            ),
        );
    }
} finally {
    if (frame)
        Object.defineProperty(globalThis, 'requestAnimationFrame', frame);
    else Reflect.deleteProperty(globalThis, 'requestAnimationFrame');
}

const copy = Object.fromEntries(
    [
        'title',
        'description',
        'template',
        'instructions',
        'file',
        'limits',
        'run',
        'results',
        'commitResults',
        'commit',
        'resume',
        'partialNotice',
        'commitNotice',
        'committedNotice',
        'invalidNotice',
        'noChanges',
        'created',
        'refused',
        'reservationNumber',
        'row',
        'reference',
        'status',
        'errors',
        'valid',
        'invalid',
        'duplicate',
        'ready',
        'empty',
    ].map((key) => [key, `copy-${key}`]),
);
const emptyImport = render(() => (
    <Import importJob={null} rows={null} copy={copy} />
));
assert.match(emptyImport, /copy-empty/);
assert.match(emptyImport, /name="_property"[^>]*value="property-1"/);
assert.match(emptyImport, /accept=".csv,text\/csv"/);
assert.match(emptyImport, /href="\/imports\/template"/);

for (const status of ['dry_run', 'committed'] as const) {
    for (const invalid of [0, 1]) {
        const html = render(() => (
            <Import
                copy={copy}
                importJob={{
                    id: 'import-1',
                    original_name: 'reservations.csv',
                    status,
                    commit_results:
                        status === 'committed'
                            ? { created: 1, refused: invalid, duplicate: 0 }
                            : null,
                    valid_rows_count: 1,
                    invalid_rows_count: invalid,
                    duplicate_rows_count: 0,
                    completed_rows_count: 1,
                }}
                rows={{
                    data: [
                        {
                            id: 'row-1',
                            row_number: 2,
                            source_reference: 'REF-1',
                            status: invalid ? 'invalid' : 'valid',
                            errors: invalid ? { arrival: ['Fix arrival'] } : {},
                            commit_result:
                                status === 'committed'
                                    ? {
                                          status: 'created',
                                          reservation_number: 'R-123',
                                      }
                                    : null,
                        },
                    ],
                    current_page: 1,
                    last_page: 2,
                    per_page: 50,
                    total: 51,
                    from: 1,
                    to: 50,
                    prev_page_url: null,
                    next_page_url: '/imports/import-1?page=2',
                    links: [
                        { url: null, label: 'Previous', active: false },
                        {
                            url: '/imports/import-1?page=1',
                            label: '1',
                            active: true,
                        },
                        {
                            url: '/imports/import-1?page=2',
                            label: '2',
                            active: false,
                        },
                        {
                            url: '/imports/import-1?page=2',
                            label: 'Next',
                            active: false,
                        },
                    ],
                }}
            />
        ));
        assert.match(html, /REF-1/);
        assert.match(html, /aria-label="Pagination"/);
        assert.match(html, /aria-label="Next page"/);
        if (status === 'dry_run') {
            assert.match(html, /copy-resume/);
            assert.match(html, /copy-partialNotice/);
            assert.match(
                html,
                invalid
                    ? /disabled=""[^>]*aria-describedby="commit-notice"/
                    : /aria-describedby="commit-notice"/,
            );
            if (invalid) assert.match(html, /Fix arrival/);
            assert.doesNotMatch(html, /copy-reservationNumber/);
        } else {
            assert.match(html, /copy-committedNotice/);
            assert.match(html, /R-123/);
            assert.doesNotMatch(
                html,
                /copy-resume|copy-commitNotice|Fix arrival/,
            );
        }
    }
}

console.log(
    'Onboarding: draft steps, guarded submit, validation recovery and import states PASS',
);
