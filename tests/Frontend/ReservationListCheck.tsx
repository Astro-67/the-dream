import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import Index, { paymentState } from '@/pages/reservations/index';
import { CheckOutDialog } from '@/features/front-desk/check-out-dialog';

function checkout(
    balances: { id: string; currency: string; balance: number }[],
    canOverride = false,
    blockCheckoutIfUnpaid = true,
) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'checkout',
                props: { blockCheckoutIfUnpaid },
                url: '/reservations',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => (
                <CheckOutDialog
                    number="R-1042"
                    balances={balances}
                    canOverride={canOverride}
                />
            )}
        />,
    );
}

const debt = { id: 'due', currency: 'USD', balance: 1500 };
const credit = { id: 'credit', currency: 'EUR', balance: -1500 };
for (const block of [true, false]) {
    const blocked = checkout([debt, credit], false, block);
    assert.match(blocked, /<button[^>]*disabled=""[^>]*>Check out<\/button>/);
    assert.match(
        blocked,
        /Check-out is not allowed\. All outstanding balances must be paid before check-out\./,
    );
    assert.match(blocked, /aria-describedby="check-out-blocked-R-1042"/);
}
assert.match(checkout([debt, { ...credit, currency: 'USD' }]), /disabled=""/);
assert.doesNotMatch(checkout([debt], true), /disabled=""/);
assert.doesNotMatch(checkout([{ ...debt, balance: 0 }]), /disabled=""/);
assert.match(checkout([credit]), /Settle every folio balance before check-out/);
assert.doesNotMatch(checkout([credit], false, false), /disabled=""/);

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
    checkout_balances: [],
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
