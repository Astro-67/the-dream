import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import { FolioBalances } from '@/features/reports/finance-report';
import { formatDate } from '@/lib/utils';

const folio = {
    id: 'folio-1',
    propertyId: 'property-1',
    reservationNumber: 'R-1001',
    status: 'checked_out',
    departure: '2026-12-12',
    departed: true,
    currency: 'TZS',
    balance: 15000,
    guest: 'Amina Report',
    room: '101',
    charges: 40000,
    netPayments: 25000,
    notYetCharged: false,
};
const page = {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    from: 1,
    to: 1,
    total: 1,
    links: [],
    prev_page_url: null,
    next_page_url: null,
};

const group = renderToStaticMarkup(
    <FolioBalances
        folios={{ ...page, data: [{ ...folio, propertyName: 'Arusha Lodge' }] }}
    />,
);
assert.match(group, />Property</);
assert.match(group, />Arusha Lodge</);
assert.match(group, /TZS 15,000/);
// A reservation opens only in its own property's context, so group rows are not links.
assert.doesNotMatch(group, /href=/);

const property = renderToStaticMarkup(
    <FolioBalances folios={{ ...page, data: [folio] }} />,
);
assert.doesNotMatch(property, />Property</);
for (const column of [
    'Guest',
    'Reservation',
    'Room',
    'Posted charges',
    'Net payments',
    'Balance',
])
    assert.match(property, new RegExp(`>${column}<`));
assert.match(property, /Amina Report/);
assert.match(property, /TZS 40,000/);
assert.match(property, /TZS 25,000/);
assert.match(property, />Due</);

// A credit is not a debt, and a settled folio with nights left says so.
const credit = renderToStaticMarkup(
    <FolioBalances
        folios={{
            ...page,
            data: [{ ...folio, balance: -5000, guest: null, room: null }],
        }}
    />,
);
assert.match(credit, /TZS -5,000/);
assert.match(credit, />Credit</);
assert.match(credit, /No guest added/);
assert.match(credit, /Unassigned/);
const settled = renderToStaticMarkup(
    <FolioBalances
        folios={{
            ...page,
            data: [{ ...folio, balance: 0, notYetCharged: true }],
        }}
    />,
);
assert.match(settled, /Not yet charged/);
assert.doesNotMatch(settled, />Settled</);
// A settled departed stay is not called open.
const departedSettled = renderToStaticMarkup(
    <FolioBalances folios={{ ...page, data: [{ ...folio, balance: 0 }] }} />,
);
assert.doesNotMatch(departedSettled, /balance open/);
assert.match(departedSettled, />Settled</);
assert.match(property, /href="[^"]*R-1001"/);

// An instant late on 14 Oct UTC is already 15 Oct in Dar es Salaam (UTC+3).
assert.equal(
    formatDate('2026-10-14T22:30:00Z', 'Africa/Dar_es_Salaam'),
    '15 Oct 2026',
);
assert.equal(formatDate('2026-10-14'), '14 Oct 2026');
console.log('Folio balances: PASS');
