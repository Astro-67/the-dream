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
assert.match(property, /href="[^"]*R-1001"/);

// An instant late on 14 Oct UTC is already 15 Oct in Dar es Salaam (UTC+3).
assert.equal(
    formatDate('2026-10-14T22:30:00Z', 'Africa/Dar_es_Salaam'),
    '15 Oct 2026',
);
assert.equal(formatDate('2026-10-14'), '14 Oct 2026');
console.log('Folio balances: PASS');
