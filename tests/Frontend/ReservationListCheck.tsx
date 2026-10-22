import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import Index, { paymentState } from '@/pages/reservations/index';

assert.deepEqual(
    paymentState({ currency: 'USD', balance: 0, unposted: true }),
    {
        label: 'Not yet charged',
        tone: 'warning',
    },
);
assert.equal(
    paymentState({ currency: 'USD', balance: 0, unposted: false }).label,
    'Settled',
);
assert.equal(
    paymentState({ currency: 'USD', balance: 1500, unposted: true }).label,
    'Due',
);
assert.equal(
    paymentState({ currency: 'TZS', balance: -5000, unposted: false }).label,
    'Credit',
);

const row = {
    id: 'r-1',
    reservation_number: 'R-1042',
    arrival: '2026-12-10',
    departure: '2026-12-12',
    status: 'checked_in',
    is_overstay: false,
    channel_reference: 'BK-778',
    requires_reconciliation: false,
    can_check_in: false,
    can_check_out: false,
    guest_name: 'Amina Lead',
    room_number: '101',
    balances: [
        { currency: 'TZS', balance: -5000, unposted: false },
        { currency: 'USD', balance: 20000, unposted: false },
        { currency: 'EUR', balance: 0, unposted: true },
    ],
};
const props = {
    reservations: {
        data: [
            row,
            {
                ...row,
                id: 'r-2',
                reservation_number: 'R-1043',
                channel_reference: null,
                guest_name: null,
                room_number: null,
                balances: [],
            },
        ],
        current_page: 1,
        last_page: 1,
        per_page: 50,
        from: 1,
        to: 2,
        total: 2,
        links: [],
        prev_page_url: null,
        next_page_url: null,
    },
    filters: { date: '2026-12-10', list: 'all', search: '' },
    canImport: false,
    canCreate: false,
    importLabel: 'Import',
};
const html = renderToStaticMarkup(
    <App
        initialPage={{
            component: 'reservations/index',
            props: { ...props, tenant: { property: null } },
            url: '/reservations',
            version: null,
            clearHistory: false,
            encryptHistory: false,
        }}
        initialComponent={() => <Index {...props} />}
    />,
);
const headers = [...html.matchAll(/<th[^>]*>(.*?)<\/th>/g)].map((match) =>
    match[1].replace(/<[^>]+>/g, ''),
);
assert.deepEqual(headers, [
    'Guest',
    'Reservation',
    'Room',
    'Check-in',
    'Check-out',
    'Payment',
    'Status',
    'Actions',
]);
assert.match(html, /Amina Lead/);
assert.match(html, /BK-778/);
assert.match(html, /Credit/);
// A credit keeps its minus sign.
assert.match(html, /TZS -5,000/);
assert.match(html, /USD 200\.00/);
// TZS and USD stay on their own lines, never summed.
assert.doesNotMatch(html, /USD 150\.00/);
// A zero balance still names its currency.
assert.match(html, /Not yet charged<\/span>EUR/);
assert.match(html, /No guest added/);
assert.match(html, /Unassigned/);
console.log('Guest-first reservation list: PASS');
