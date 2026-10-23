import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { PaymentsTable } from '@/features/reports/collections-table';
import Show from '@/pages/receipts/show';

const base = {
    receipt: {
        id: 'rc-1',
        number: 'RCT-000007',
        label: 'Non-fiscal receipt',
        vfms_receipt_number: null,
        issued_at: '2026-12-10T09:00:00Z',
        amount: 2000,
        currency: 'USD',
        deposit: false,
        method: 'bank_transfer',
        reference: 'TRX-1',
        card_last_four: null,
        card_brand: null,
        cashier: 'Neema Cashier',
    },
    reservation: {
        reservation_number: 'R-1042',
        payment_collector: 'property',
    },
    booker: { name: 'Amina Lead' },
    property: {
        name: 'Sea Inn',
        address_line: 'Stone Town',
        city: 'Zanzibar',
        phone: '+255',
        email: 'desk@example.test',
    },
    stay: {
        reservation_number: 'R-1042',
        guest: 'Amina Lead',
        room: '101',
        room_type: 'Deluxe Double',
        arrival: '2026-12-10',
        departure: '2026-12-12',
    },
    corrections: [
        { type: 'reversal', amount: 2000, business_date: '2026-12-11' },
    ],
    summary: {
        currency: 'USD',
        room: 0,
        extras: 5000,
        vat: 0,
        levy: 0,
        other_taxes: 0,
        unclassified_taxes: 0,
        charges: 5000,
        paid: 2000,
        refunded: 0,
        written_off: 0,
        net_payments: 2000,
        balance: 3000,
        levy_unavailable: false,
    },
};
const render = (
    props: typeof base | (Omit<typeof base, 'summary'> & { summary: null }),
) =>
    renderToStaticMarkup(
        <App
            initialPage={{
                component: 'receipts/show',
                props: { ...props, tenant: { property: null } },
                url: '/receipts/rc-1',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => <Show {...props} />}
        />,
    );

const html = render(base);
assert.match(html, /NON-FISCAL RECEIPT/);
assert.match(html, /Folio summary at payment time/);
assert.match(html, /USD 30\.00/);
assert.match(html, /Neema Cashier/);
assert.match(html, /101, Deluxe Double/);
assert.match(html, /Later corrections/);
assert.match(html, /reservations\/R-1042\/statement/);
assert.doesNotMatch(html, /Historical folio summary unavailable/);
assert.match(
    render({ ...base, receipt: { ...base.receipt, method: 'other' } }),
    /<dd[^>]*>Other<\/dd>/,
);

const noLevy = render({
    ...base,
    summary: { ...base.summary, levy_unavailable: true },
});
assert.match(noLevy, /Infrastructure levy.*?Not available in this currency/);
assert.doesNotMatch(html, /Not available in this currency/);

const old = render({ ...base, summary: null, corrections: [] });
assert.match(old, /Historical folio summary unavailable/);
assert.doesNotMatch(old, /Balance due/);

const page = {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    from: 1,
    to: 2,
    total: 2,
    links: [],
    prev_page_url: null,
    next_page_url: null,
};
const row = {
    id: 'p-1',
    type: 'payment',
    category: 'deposit',
    business_date: '2026-12-10',
    posted_at: '2026-12-10T09:00:00Z',
    receipt: { id: 'rc-1', number: 'RCT-000007' },
    guest: 'Amina Lead',
    reservation_number: 'R-1042',
    room: null,
    method: 'other',
    reference: null,
    amount: 2000,
    currency: 'USD',
    processed_by: null,
};
const table = renderToStaticMarkup(
    <PaymentsTable
        payments={{
            ...page,
            data: [
                row,
                {
                    ...row,
                    id: 'p-2',
                    type: 'reversal',
                    receipt: null,
                    amount: -2000,
                },
            ],
        }}
    />,
);
const headers = [...table.matchAll(/<th\b[^>]*>(.*?)<\/th>/g)].map((match) =>
    match[1].replace(/<[^>]+>/g, ''),
);
assert.deepEqual(headers, [
    'Date',
    'Receipt',
    'Guest',
    'Reservation',
    'Room',
    'Method',
    'Reference',
    'Amount',
    'Processed by',
]);
assert.match(table, /receipts\/rc-1/);
assert.match(table, /Deposit/);
assert.match(table, /Reversed payment/);
assert.match(table, /USD -20\.00/);
assert.match(table, /Unassigned/);
assert.equal((table.match(/>Other</g) ?? []).length, 2);
console.log('Receipt summary and individual payments: PASS');
