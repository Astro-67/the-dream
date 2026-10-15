import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';

function sidebar(platformRoles: string[]) {
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
                        permissions: [],
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
