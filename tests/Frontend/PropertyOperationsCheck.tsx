import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import PropertyOperations from '@/features/reports/property-operations';

const empty = renderToStaticMarkup(
    <PropertyOperations
        operations={{
            daily: [
                {
                    date: '2026-12-10',
                    arrivals: 0,
                    departures: 0,
                    inHouse: 0,
                },
            ],
            housekeeping: [
                { status: 'dirty', label: 'Dirty', tone: 'warning', rooms: 0 },
            ],
        }}
    />,
);

assert.match(empty, /No arrivals, departures or in-house stays/);
assert.match(empty, /Change the business date range/);
assert.match(empty, /No active rooms/);
assert.match(empty, /not a historical snapshot/);
assert.match(empty, /arrival date is included and departure date is excluded/);
assert.doesNotMatch(empty, /<table/);
console.log('Empty property operations: PASS');
