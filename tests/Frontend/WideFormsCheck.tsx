import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import Maintenance from '@/pages/maintenance/index';
import Create from '@/pages/reservations/create';

function render(Page: ComponentType) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'page',
                url: '/',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    auth: {
                        user: { id: 'u' },
                        platformRoles: [],
                        permissions: [],
                    },
                    tenant: { property: { id: 'p-1' } },
                    countries: [],
                },
            }}
            initialComponent={Page}
            resolveComponent={async () => Page}
        />,
    );
}

function maintenance(room: string | null) {
    return render(() => (
        <Maintenance
            view="open"
            tickets={{ data: [], links: [], meta: {} } as never}
            rooms={[{ id: 'r-1', number: '101' }]}
            room={room}
            categories={[{ value: 'repair', label: 'Repair' }]}
            priorities={[{ value: 'medium', label: 'Medium' }]}
        />
    ));
}
// Dialog content portals out of server markup, so the trigger's state stands in for it.
assert.match(maintenance(null), /aria-expanded="false"[^>]*>Report a problem</);
assert.match(
    maintenance('r-1'),
    /aria-expanded="true"[^>]*>Report a problem</,
    'A room link opens the report form',
);
console.log('Maintenance report form opens on request: PASS');

const booking = render(() => (
    <Create
        stay={{
            arrival: '2026-12-10',
            departure: '2026-12-13',
            adults: 2,
            children: 0,
        }}
        roomType={null}
        ratePlan={{ id: 'plan', name: 'Standard', currency: 'USD' }}
        ratePlans={[
            {
                id: 'plan',
                name: 'Standard',
                currency: 'USD',
                room_type_ids: [],
            },
        ]}
        rooms={[]}
        restrictionWarnings={[]}
        nightlyRate={10000}
        businessDate="2026-12-01"
    />
));
assert.match(booking, /<dt[^>]*>Nights<\/dt><dd[^>]*>3<\/dd>/);
assert.match(booking, /USD 100\.00/);
assert.match(booking, /<aside[^>]*>[\s\S]*Confirm reservation[\s\S]*<\/aside>/);
console.log('Booking summary keeps confirm beside the stay: PASS');
