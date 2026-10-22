import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import CashierShifts from '@/pages/cashier-shifts/index';

function sidebar(
    platformRoles: string[],
    permissions: string[] = [],
    useShifts = true,
) {
    const component = () => (
        <TooltipProvider>
            <SidebarProvider>
                <AppSidebar />
            </SidebarProvider>
        </TooltipProvider>
    );

    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'platform/organizations/index',
                url: '/platform/organizations',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    auth: {
                        user: {
                            id: 'sample',
                            name: 'Sample administrator',
                            email: 'administrator@example.test',
                        },
                        platformRoles,
                        permissions,
                    },
                    tenant: {
                        organization: null,
                        property: {
                            paymentSettings: { use_cashier_shifts: useShifts },
                        },
                        organizations: [],
                        properties: [],
                    },
                },
            }}
            initialComponent={component}
            resolveComponent={async () => component}
        />,
    );
}

const administrator = sidebar(['platform_administrator']);
assert.equal(
    /<a\b[^>]*href="\/platform\/organizations"[^>]*>Platform administration<\/a>/.test(
        administrator,
    ),
    true,
    'Platform administration must be a working navigation link',
);
for (const path of ['organizations', 'plans', 'invoices', 'staff']) {
    assert.ok(administrator.includes(`href="/platform/${path}"`));
}
assert.ok(!sidebar([]).includes('href="/platform/'));
assert.ok(!sidebar(['compliance_operator']).includes('href="/platform/'));
console.log('Platform sidebar navigation: PASS');

const restricted = sidebar([]);
assert.match(restricted, />Today</);
assert.doesNotMatch(restricted, />Dashboard</);
for (const label of ['Money', 'Setup', 'More']) {
    assert.ok(
        !restricted.includes(`>${label}<`),
        `${label} must hide when empty`,
    );
}

const finance = sidebar([], ['finance.view']);
assert.match(finance, />Money</);
assert.match(finance, />Cashier shifts</);
assert.match(finance, />Open balances</);
assert.doesNotMatch(finance, />Payments</);
assert.doesNotMatch(finance, />Reports</);
assert.doesNotMatch(finance, />Setup</);

const reservations = sidebar([], ['reservations.view']);
assert.match(reservations, />Reservations</);
assert.match(reservations, />Setup</);
for (const label of ['Rooms', 'Room types', 'Rate plans', 'Taxes']) {
    assert.ok(reservations.includes(`>${label}<`));
}
assert.match(reservations, /aria-expanded="false"[^>]*>.*?<span>More<\/span>/);
assert.doesNotMatch(reservations, />Availability</);
assert.match(reservations, />Calendar</);
assert.doesNotMatch(reservations, />Properties</);
assert.doesNotMatch(reservations, />Members</);

const housekeeper = sidebar([], ['housekeeping.manage', 'maintenance.view']);
assert.match(housekeeper, />My rooms</);
assert.match(housekeeper, />Maintenance</);
assert.doesNotMatch(finance, />Maintenance</);
assert.doesNotMatch(housekeeper, />Housekeeping</);
assert.doesNotMatch(housekeeper, />Setup</);
console.log('Grouped, permission-filtered sidebar: PASS');
assert.doesNotMatch(sidebar([], ['finance.view'], false), />Cashier shifts</);
const historyComponent = () => (
    <CashierShifts
        canOpenShift={false}
        canCloseShift={false}
        usesCashierShifts={false}
        openShift={null}
        currencies={['USD']}
        shifts={{
            data: [
                {
                    id: 'closed-shift',
                    cashier: 'Historical cashier',
                    status: 'closed',
                    opened_at: '2026-12-12T10:00:00Z',
                    closed_at: '2026-12-12T12:00:00Z',
                    variance_reason: null,
                    currencies: [
                        {
                            currency: 'USD',
                            float: 0,
                            expected: 1500,
                            counted: 1500,
                            variance: 0,
                        },
                    ],
                },
            ],
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: 1,
            from: 1,
            to: 1,
            links: [],
            prev_page_url: null,
            next_page_url: null,
        }}
    />
);
const history = renderToStaticMarkup(
    <App
        initialPage={{
            component: 'cashier-shifts/index',
            url: '/cashier-shifts',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            props: { errors: {}, tenant: { property: null } },
        }}
        initialComponent={historyComponent}
    />,
);
assert.match(history, /Cashier shifts are turned off/);
assert.match(history, /Historical cashier/);
assert.match(history, /USD 15\.00/);
assert.doesNotMatch(history, /Open a shift/);
