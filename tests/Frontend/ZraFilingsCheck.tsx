import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import ZraFilings from '@/features/reports/zra-filings';

const page = {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    from: 1,
    to: 2,
    total: 2,
    prev_page_url: null,
    next_page_url: null,
    links: [],
};
const counts = [
    { status: 'filed', label: 'Filed', count: 1 },
    { status: 'refused', label: 'Refused', count: 1 },
    { status: 'unknown', label: 'Unknown', count: 0 },
    { status: 'waiting', label: 'Waiting', count: 0 },
] as const;
const range = { from: '2026-12-10', to: '2026-12-11' };

const listed = renderToStaticMarkup(
    <ZraFilings
        range={range}
        filings={{
            counts: [...counts],
            calls: {
                ...page,
                data: [
                    {
                        id: 'a',
                        date: '2026-12-10',
                        reservationNumber: 'R-0001',
                        call: 'Check-in',
                        amount: 11000,
                        currency: 'USD',
                        status: 'filed',
                        statusLabel: 'Filed',
                        zraNumber: 'AC-174',
                    },
                    {
                        id: 'b',
                        date: '2026-12-10',
                        reservationNumber: 'R-0001',
                        call: 'Extras',
                        amount: 2000,
                        currency: 'USD',
                        status: 'refused',
                        statusLabel: 'Refused',
                        zraNumber: null,
                    },
                ],
            },
        }}
    />,
);

for (const text of [
    'Filed',
    'Refused',
    'Unknown',
    'Waiting',
    'Check-in',
    'Extras',
    'AC-174',
    'USD 110.00',
    'None yet',
]) {
    assert.ok(listed.includes(text), `missing ${text}`);
}
assert.match(listed, /zra-filings\.csv\?from=2026-12-10&amp;to=2026-12-11/);
assert.match(listed, /text-status-error/);
assert.doesNotMatch(listed, /(text|bg)-(red|green|amber|yellow|blue)-\d/);

const empty = renderToStaticMarkup(
    <ZraFilings
        range={range}
        filings={{
            counts: [...counts],
            calls: { ...page, total: 0, data: [] },
        }}
    />,
);
assert.match(empty, /No ZRA filings for stay events in this period/);
console.log('ZRA filings report: PASS');
