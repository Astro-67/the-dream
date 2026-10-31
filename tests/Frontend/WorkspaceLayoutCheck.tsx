import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { AppContent } from '@/components/app-content';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import SettingsLayout from '@/layouts/settings/layout';
import AppLayout from '@/layouts/app-layout';
import BookerShow from '@/pages/bookers/show';
import BookerEdit from '@/pages/bookers/edit';
import BookerCreate from '@/pages/bookers/create';
import Dashboard from '@/pages/dashboard';

function dashboard(property: boolean) {
    const component = () => (
        <Dashboard
            onboarding={null}
            today={null}
            collections={null}
            roomBoard={null}
        />
    );

    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'dashboard',
                url: '/dashboard',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    auth: {
                        user: { name: 'Front desk' },
                        permissions: ['reservations.manage'],
                    },
                    tenant: {
                        organization: { name: 'Sample organization' },
                        property: property ? { name: 'Sample property' } : null,
                    },
                },
            }}
            initialComponent={component}
        />,
    );
}

const operational = dashboard(true);
assert.match(operational, /Welcome back, Front/);
assert.match(operational, /New booking/);
assert.match(operational, /Use the menu/);
assert.doesNotMatch(
    operational,
    /max-w-/,
    'The operational dashboard must use the available workspace, even when sparse',
);
assert.match(
    dashboard(false),
    /max-w-\[720px\]/,
    'The no-property reading state keeps a readable measure',
);
console.log('Dashboard workspace and reading widths: PASS');

for (const sidebarOpen of [true, false]) {
    const component = () => (
        <TooltipProvider>
            <AppSidebarLayout>
                <SettingsLayout>
                    <form aria-label="Workspace content">
                        <input aria-label="Name" />
                    </form>
                </SettingsLayout>
            </AppSidebarLayout>
        </TooltipProvider>
    );
    const shell = renderToStaticMarkup(
        <App
            initialPage={{
                component: 'dashboard',
                url: '/dashboard',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    sidebarOpen,
                    auth: {
                        user: {
                            id: 'staff',
                            name: 'Front desk',
                            email: 'staff@example.test',
                        },
                        permissions: ['reservations.view'],
                        platformRoles: [],
                    },
                    tenant: {
                        organization: null,
                        property: {
                            name: 'Stone Town Waterfront Hotel with a long property name',
                            businessDate: '2026-12-10',
                            jurisdictionLabel: 'Zanzibar',
                            paymentSettings: { use_cashier_shifts: false },
                        },
                        organizations: [],
                        properties: [],
                    },
                },
            }}
            initialComponent={component}
        />,
    );
    assert.match(
        shell,
        new RegExp(`data-state="${sidebarOpen ? 'expanded' : 'collapsed'}"`),
    );
    assert.match(shell, /data-variant="sidebar"/);
    assert.match(
        shell,
        sidebarOpen ? /data-collapsible=""/ : /data-collapsible="icon"/,
    );
    assert.equal((shell.match(/<main\b/g) ?? []).length, 1);
    assert.match(shell, /Workspace content/);
    assert.doesNotMatch(shell, /overflow-x-clip/);
    assert.match(shell, /href="\/reservations"/);
    assert.match(shell, /Toggle sidebar/);
    assert.match(shell, /aria-label="Settings"/);
    assert.match(shell, /href="\/settings\/profile"/);
    assert.match(shell, /<section[^>]*max-w-\[720px\]/);
    assert.doesNotMatch(shell, /lg:space-x-12|md:max-w-2xl/);
    assert.match(
        shell,
        /Stone Town Waterfront Hotel with a long property name/,
    );
    assert.match(shell, /10 Dec 2026/);
    assert.match(
        shell,
        /<header[^>]*flex-wrap/,
        'The sticky header must reflow rather than squeeze property context out',
    );
}

const headerWorkspace = renderToStaticMarkup(
    <AppContent variant="header" className="gap-6">
        Header workspace
    </AppContent>,
);
assert.match(headerWorkspace, /min-w-0/);
assert.match(headerWorkspace, /gap-6/);
assert.doesNotMatch(headerWorkspace, /max-w-|mx-auto/);
console.log('Expanded, collapsed and alternate shell contracts: PASS');

const booker = {
    id: 'booker-207',
    name: 'Coastal travel partner with a long name',
    email: null,
    phone: null,
    nationality: null,
    preferences: null,
    notes: null,
};

for (const sidebarOpen of [true, false]) {
    for (const [Page, current, path] of [
        [BookerShow, 'Booker details', '/bookers/booker-207'],
        [BookerEdit, 'Edit booker', '/bookers/booker-207/edit'],
        [BookerCreate, 'Add booker', '/bookers/create'],
    ] as const) {
        const markup = renderToStaticMarkup(
            <TooltipProvider>
                <App
                    initialPage={{
                        component: 'bookers/page',
                        url: path,
                        version: null,
                        clearHistory: false,
                        encryptHistory: false,
                        props: {
                            record: booker,
                            canManage: true,
                            errors: {},
                            countries: [],
                            sidebarOpen,
                            auth: {
                                user: {
                                    name: 'Front desk',
                                    email: 'staff@example.test',
                                },
                                permissions: ['bookers.manage'],
                                platformRoles: [],
                            },
                            tenant: {
                                organization: {
                                    id: 'org-207',
                                    name: 'Coastal organization',
                                },
                                property: {
                                    id: 'property-207',
                                    name: 'Stone Town Waterfront Hotel with a long property name',
                                    businessDate: '2026-12-10',
                                    paymentSettings: {
                                        use_cashier_shifts: false,
                                    },
                                },
                                organizations: [],
                                properties: [],
                            },
                        },
                    }}
                    initialComponent={Page}
                    defaultLayout={() => AppLayout}
                />
            </TooltipProvider>,
        );
        assert.match(markup, /<a[^>]*href="\/bookers"[^>]*>Bookers<\/a>/);
        assert.match(
            markup,
            new RegExp(`<span[^>]*aria-current="page"[^>]*>${current}</span>`),
        );
        assert.doesNotMatch(markup, new RegExp(`<a[^>]*>${current}</a>`));
        assert.match(
            markup,
            Page === BookerEdit
                ? /<a[^>]*href="\/bookers\/booker-207"[^>]*>[\s\S]*?Back to Coastal travel partner with a long name<\/a>/
                : /<a[^>]*href="\/bookers"[^>]*>[\s\S]*?Back to Bookers<\/a>/,
        );
        assert.match(
            markup,
            /Stone Town Waterfront Hotel with a long property name/,
        );
        assert.match(markup, /10 Dec 2026/);
        if (Page !== BookerShow) {
            assert.match(markup, /<a[^>]*href="\/bookers"[^>]*>Cancel<\/a>/);
            assert.match(markup, /name="_property"[^>]*value="property-207"/);
            assert.match(markup, /<button[^>]*type="submit"/);
        }
    }
}
assert.doesNotMatch(operational, /Back to /);
console.log('Direct-entry Booker parents, breadcrumbs and form actions: PASS');
