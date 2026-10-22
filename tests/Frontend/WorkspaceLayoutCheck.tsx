import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { AppContent } from '@/components/app-content';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import Dashboard from '@/pages/dashboard';

function dashboard(property: boolean) {
    const component = () => (
        <Dashboard onboarding={null} today={null} collections={null} />
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
                <div>Workspace content</div>
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
                        property: null,
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
