import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';

function sidebar(platformRoles: string[], permissions: string[] = []) {
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
                        property: null,
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

const housekeeper = sidebar([], ['housekeeping.manage']);
assert.match(housekeeper, />My rooms</);
assert.doesNotMatch(housekeeper, />Housekeeping</);
assert.doesNotMatch(housekeeper, />Setup</);
console.log('Grouped, permission-filtered sidebar: PASS');
