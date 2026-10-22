import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
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
