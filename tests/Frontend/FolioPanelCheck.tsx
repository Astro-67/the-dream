import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { PostingLink } from '@/features/billing/folio-corrections';
import {
    FolioPanel,
    type Folio,
    type Posting,
} from '@/features/billing/folio-panel';
import type { FolioSummary } from '@/features/billing/folio-summary';
import type { Paginated } from '@/types';

const posting: Posting = {
    id: 'posting-in',
    type: 'transfer_in',
    category: 'laundry',
    description: null,
    reason: 'Company pays',
    related: {
        id: 'posting-out',
        type: 'transfer_out',
        category: 'laundry',
        folio_id: 'off-page-source',
    },
    cancelled_by: null,
    quantity: 1,
    unit_price: 100,
    net: 100,
    amount: 100,
    tax_lines: [],
    posted_at: '2026-12-10T09:00:00Z',
    actor: null,
};

const emptySummary: FolioSummary = {
    room: 0,
    extras: 0,
    vat: 0,
    levy: 0,
    other_taxes: 0,
    unclassified_taxes: 0,
    charges: 0,
    paid: 0,
    refunded: 0,
    written_off: 0,
    net_payments: 0,
    balance: 0,
    levy_unavailable: false,
};

const incoming = renderToStaticMarkup(<PostingLink posting={posting} />);
assert.match(incoming, /off-page-source/);
assert.match(incoming, /Copy off-page-source/);
const outgoing = renderToStaticMarkup(
    <PostingLink
        posting={{
            ...posting,
            type: 'charge',
            related: null,
            cancelled_by: {
                id: 'posting-out',
                type: 'transfer_out',
                folio_id: 'original-source',
                destination_folio_id: 'off-page-destination',
            },
        }}
    />,
);
assert.match(outgoing, /off-page-destination/);
assert.match(outgoing, /Copy off-page-destination/);
assert.doesNotMatch(outgoing, /original-source/);
const refunded = renderToStaticMarkup(
    <PostingLink
        posting={{
            ...posting,
            type: 'payment',
            related: null,
            reason: null,
            cancelled_by: {
                id: 'refund',
                type: 'refund',
                folio_id: 'f',
                destination_folio_id: null,
            },
        }}
    />,
);
assert.match(refunded, /Refunded/);
assert.doesNotMatch(refunded, /Moved out/);
console.log('Off-page transfer identity: PASS');

function pageOf<Item>(data: Item[]): Paginated<Item> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: 50,
        from: data.length ? 1 : null,
        to: data.length || null,
        total: data.length,
        prev_page_url: null,
        next_page_url: null,
        links: [],
    };
}

const folios: Folio[] = Array.from({ length: 50 }, (_, index) => ({
    id: `folio-${index + 1}`,
    number: index + 1,
    currency: 'USD',
    balance: 0,
    postings: pageOf<Posting>([]),
}));
folios[0].postings = pageOf([
    {
        ...posting,
        id: 'free-text',
        type: 'charge',
        category: 'other',
        description: 'Sunset boat trip',
    },
    { ...posting, id: 'legacy', type: 'charge', category: 'minibar' },
    { ...posting, id: 'room', type: 'charge', category: 'room' },
    {
        ...posting,
        id: 'payment',
        type: 'payment',
        category: 'payment',
        method: 'card',
    },
]);
const folioPage = {
    ...pageOf(folios),
    total: 51,
    last_page: 2,
    next_page_url: '/reservation?folios_page=2',
    links: [
        { url: null, label: 'Previous', active: false },
        { url: '/reservation?folios_page=1', label: '1', active: true },
        { url: '/reservation?folios_page=2', label: '2', active: false },
        { url: '/reservation?folios_page=2', label: 'Next', active: false },
    ],
};
const panel = renderToStaticMarkup(
    <App
        initialPage={{
            component: 'FolioPanelCheck',
            url: '/reservation',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            props: {
                errors: {},
                tenant: { property: { id: 'property', timezone: 'UTC' } },
                reservation: { reservation_number: 'R-1042' },
                canRefund: false,
                canWriteOff: false,
                canOpenFolio: false,
                canReverse: false,
                canTransfer: false,
                folioCurrencies: ['USD'],
            },
        }}
        initialComponent={() => (
            <FolioPanel
                reservationNumber="R-1042"
                folios={folioPage}
                totals={[
                    {
                        currency: 'USD',
                        ...emptySummary,
                        estimate: { nights: 2, amount: 20000 },
                    },
                ]}
                canPostCharge
                canRecordPayment
                acceptedPaymentMethods={['card']}
            />
        )}
    />,
);
assert.equal((panel.match(/<form\b/g) ?? []).length, 2);
// A zero balance with nights still to charge is not called settled.
assert.match(panel, /Balance of posted charges/);
assert.doesNotMatch(panel, /Settled/);
assert.match(panel, /Not yet charged: 2 nights/);
assert.match(panel, /reservations\/R-1042\/statement/);
assert.equal((panel.match(/aria-label="View folio /g) ?? []).length, 50);
assert.match(panel, /folios_page=2/);
assert.match(panel, /Sunset boat trip/);
assert.match(panel, /Minibar/);
assert.match(panel, /Room charge/);
assert.match(panel, /Payment/);
assert.match(panel, /Item name/);
assert.match(panel, /VAT-inclusive unit price/);
assert.doesNotMatch(panel, /name="category"/);
assert.match(panel, /<option value="card" selected="">/);
assert.doesNotMatch(panel, /<option value="bank_transfer"/);
assert.doesNotMatch(panel, /<option value="cash"/);
console.log('Only the selected folio mounts action forms: PASS');
