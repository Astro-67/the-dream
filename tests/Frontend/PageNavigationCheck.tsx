import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';

const cases = [
    ['bookers/create', 'Bookers', '/bookers', 'Add booker'],
    ['bookers/edit', 'Amina Said', '/bookers/contact-207', 'Edit booker'],
    ['bookers/show', 'Bookers', '/bookers', 'Booker details'],
    ['guests/create', 'Guests', '/guests', 'Add guest'],
    ['guests/edit', 'Amina Said', '/guests/contact-207', 'Edit guest'],
    ['guests/show', 'Guests', '/guests', 'Guest details'],
    [
        'guests/merge',
        'Amina Said',
        '/guests/contact-207',
        'Merge duplicate guests',
    ],
    ['properties/create', 'Properties', '/properties', 'Add property'],
    ['properties/edit', 'Properties', '/properties', 'Edit property'],
    ['members/edit', 'Staff', '/members', 'Edit access'],
    ['invitations/create', 'Staff', '/members', 'Add staff'],
    [
        'inventory/room-types/create',
        'Room types',
        '/room-types',
        'Add room type',
    ],
    [
        'inventory/room-types/edit',
        'Room types',
        '/room-types',
        'Edit room type',
    ],
    ['inventory/rooms/create', 'Rooms', '/rooms', 'Add room'],
    ['inventory/rooms/edit', 'Rooms', '/rooms', 'Edit room'],
    ['rates/create', 'Rate plans', '/rate-plans', 'Add rate plan'],
    ['rates/edit', 'Prices', '/rate-plans/plan-207/prices', 'Edit rate plan'],
    ['rates/prices', 'Rate plans', '/rate-plans', 'Prices'],
    [
        'rates/restrictions',
        'Prices',
        '/rate-plans/plan-207/prices',
        'Restrictions',
    ],
    ['rates/quote', 'Prices', '/rate-plans/plan-207/prices', 'Quote preview'],
    ['rates/tax-preview', 'Tax rules', '/tax-rules', 'Tax preview'],
    ['reservations/create', 'Reservations', '/reservations', 'New reservation'],
    [
        'reservations/show',
        'Reservations',
        '/reservations',
        'Reservation details',
    ],
    ['reservations/guests', 'R-207', '/reservations/R-207', 'Guests'],
    [
        'reservations/statement',
        'R-207',
        '/reservations/R-207',
        'Folio statement',
    ],
    ['receipts/show', 'R-207', '/reservations/R-207', 'Receipt'],
    [
        'fiscal-receipting/checkout-report',
        'R-207',
        '/reservations/R-207',
        'Fiscal check-out report',
    ],
    ['night-audit/show', 'Night audit', '/night-audit', 'Close details'],
    ['maintenance/show', 'Maintenance', '/maintenance', 'Ticket'],
    ['housekeeping/history', 'Housekeeping', '/housekeeping', 'Room history'],
    [
        'housekeeping/assignments',
        'Housekeeping',
        '/housekeeping',
        'Assignments',
    ],
    [
        'platform/organizations/show',
        'Organizations',
        '/platform/organizations',
        'Organization details',
    ],
] as const;

const shared = {
    errors: {},
    auth: {
        user: { name: 'Front desk', email: 'staff@example.test' },
        permissions: [],
        platformRoles: [],
    },
    tenant: {
        organization: { id: 'org-207', name: 'Coastal organization' },
        property: {
            id: 'property-207',
            name: 'Stone Town Waterfront Hotel',
            businessDate: '2026-12-10',
            paymentSettings: { use_cashier_shifts: false },
        },
        organizations: [],
        properties: [],
    },
    plan: { id: 'plan-207' },
    record: { id: 'contact-207', name: 'Amina Said' },
    guest: { id: 'contact-207', name: 'Amina Said' },
    reservation: { reservation_number: 'R-207' },
    stay: { reservation_number: 'R-207' },
    reservationNumber: 'R-207',
};

async function navigation(path: string, url = '/direct-entry') {
    const { default: Page } = await import(
        `../../resources/js/pages/${path}.tsx`
    );
    const Content = () => <p>Page content</p>;
    Content.layout = Page.layout;
    return renderToStaticMarkup(
        <TooltipProvider>
            <App
                initialPage={{
                    component: path,
                    url,
                    version: null,
                    clearHistory: false,
                    encryptHistory: false,
                    props: shared,
                }}
                initialComponent={Content}
                defaultLayout={() => AppLayout}
            />
        </TooltipProvider>,
    );
}

for (const [path, parent, href, current] of cases) {
    const markup = await navigation(path);
    const ancestors = [...markup.matchAll(/<a\b[^>]*>[^<]*<\/a>/g)];
    assert.ok(
        ancestors.some(
            ([link]) =>
                link.includes(`href="${href}"`) &&
                link.endsWith(`>${parent}</a>`),
        ),
        `${path}: linked ancestor`,
    );
    const returnLink =
        markup.match(
            /<nav aria-label="Parent page"[^>]*>[\s\S]*?<\/nav>/,
        )?.[0] ?? '';
    assert.ok(
        returnLink.includes(`href="${href}"`),
        `${path}: deterministic parent destination`,
    );
    assert.ok(
        returnLink.includes(`Back to ${parent}</a>`),
        `${path}: labelled return link`,
    );
    const currentItem =
        markup.match(/<span[^>]*aria-current="page"[^>]*>[^<]*<\/span>/)?.[0] ??
        '';
    assert.ok(
        currentItem.endsWith(`>${current}</span>`),
        `${path}: distinct current breadcrumb`,
    );
    assert.doesNotMatch(
        currentItem,
        /href=|tabindex=/,
        `${path}: current item is not navigable`,
    );
    assert.doesNotMatch(returnLink, /href="#"|javascript:|history/);
}

for (const [url, parent, href] of [
    ['/subscription/invoices/invoice-207', 'Subscription', '/subscription'],
    ['/platform/invoices/invoice-207', 'SaaS invoices', '/platform/invoices'],
] as const) {
    const markup = await navigation('subscription/invoice', url);
    assert.ok(
        [...markup.matchAll(/<a\b[^>]*>[^<]*<\/a>/g)].some(
            ([link]) =>
                link.includes(`href="${href}"`) &&
                link.endsWith(`>${parent}</a>`),
        ),
        `${url}: correct scope`,
    );
    assert.ok(markup.includes(`Back to ${parent}</a>`), `${url}: parent link`);
}

for (const path of [
    'dashboard',
    'reservations/index',
    'reservations/availability',
    'rates/index',
    'rates/calendar',
    'rates/taxes',
    'subscription/show',
    'platform/billing-details/edit',
    'housekeeping/my-rooms',
    'onboarding/import',
    'settings/profile',
    'reports/index',
    'reports/group',
]) {
    assert.doesNotMatch(
        await navigation(path),
        /aria-label="Parent page"/,
        `${path}: root has no artificial parent`,
    );
}
console.log(
    `Nested Inertia navigation: ${cases.length + 2} destinations; root pages: PASS`,
);
