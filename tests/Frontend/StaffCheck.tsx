import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import CreateInvitation from '@/pages/invitations/create';
import Members from '@/pages/members/index';

function render(Page: ComponentType, permissions: string[] = []) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'staff',
                url: '/members',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    auth: { user: { id: 'u' }, platformRoles: [], permissions },
                    tenant: { property: { id: 'p-1' } },
                },
            }}
            initialComponent={Page}
            resolveComponent={async () => Page}
        />,
    );
}

const invitation = {
    id: 'i-1',
    email: 'amina@example.test',
    role: 'front_desk',
    roleLabel: 'Reception',
    allProperties: false,
    properties: ['Stone Town Inn'],
    invitedBy: 'Owner',
    expiresAt: '2026-12-10T00:00:00Z',
    canRevoke: true,
};
const staff = render(
    () => <Members members={[]} invitations={[invitation]} canInvite />,
    ['members.invite'],
);
assert.match(staff, />Staff</);
assert.match(staff, />Add staff</);
assert.match(staff, />Pending invitations</);
assert.match(staff, /amina@example\.test/);
assert.match(staff, />Reception</);
assert.match(staff, />Revoke</);

const viewer = render(() => (
    <Members members={[]} invitations={[]} canInvite={false} />
));
assert.doesNotMatch(viewer, /Add staff/);
assert.doesNotMatch(viewer, /Pending invitations/);
console.log('Staff list with pending invitations: PASS');

const form = render(() => (
    <CreateInvitation
        roles={[
            {
                value: 'front_desk',
                label: 'Reception',
                summary: 'Reservations and taking payments.',
                mayHaveAllProperties: false,
            },
        ]}
        properties={[
            { value: 'p-1', label: 'Stone Town Inn' },
            { value: 'p-2', label: 'Arusha Lodge' },
        ]}
        defaultExpiryDays={7}
    />
));
assert.match(form, />Add staff</);
assert.match(
    form.replace(/<[^>]+>/g, ''),
    /Reception: Reservations and taking payments\./,
);
assert.match(form, /never see or set their password/);
assert.match(
    form.match(/<button[^>]*id="invite-property-p-1"[^>]*>/)?.[0] ?? '',
    /aria-checked="true"/,
);
assert.match(
    form.match(/<button[^>]*id="invite-property-p-2"[^>]*>/)?.[0] ?? '',
    /aria-checked="false"/,
);
assert.match(form, /name="all_properties" value="0"/);
assert.doesNotMatch(form, /type="password"/);
console.log('Add staff form explains roles: PASS');
