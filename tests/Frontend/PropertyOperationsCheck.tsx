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

const populated = renderToStaticMarkup(
    <PropertyOperations
        operations={{
            daily: [
                { date: '2026-12-10', arrivals: 2, departures: 1, inHouse: 3 },
                { date: '2026-12-11', arrivals: 0, departures: 0, inHouse: 0 },
            ],
            housekeeping: [
                { status: 'dirty', label: 'Dirty', tone: 'warning', rooms: 4 },
                { status: 'clean', label: 'Clean', tone: 'success', rooms: 0 },
            ],
        }}
    />,
);

assert.match(populated, /10 Dec 2026/);
assert.match(populated, /11 Dec 2026/);
assert.match(populated, />Arrivals</);
assert.match(populated, />Departures</);
assert.match(populated, />In-house</);
assert.match(populated, />Dirty</);
assert.match(populated, />Clean</);
assert.match(populated, />4</);
assert.equal((populated.match(/<table /g) ?? []).length, 2);
assert.doesNotMatch(populated, /No active rooms/);
console.log('Populated property operations: PASS');
