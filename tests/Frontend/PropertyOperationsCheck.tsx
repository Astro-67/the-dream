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
                    plannedArrivals: 0,
                    plannedDepartures: 0,
                },
            ],
            historyUnavailable: 0,
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
assert.match(
    empty,
    /check-in business date is included and check-out business date is excluded/,
);
assert.match(empty, /recorded lifecycle business dates/);
assert.match(empty, /not evidence of physical arrival or departure/);
assert.match(empty, /No future in-house nights are inferred/);
assert.doesNotMatch(empty, /actually left/);
assert.doesNotMatch(empty, /<table/);
console.log('Empty property operations: PASS');

const populated = renderToStaticMarkup(
    <PropertyOperations
        operations={{
            daily: [
                {
                    date: '2026-12-10',
                    arrivals: 2,
                    departures: 1,
                    inHouse: 3,
                    plannedArrivals: 0,
                    plannedDepartures: 2,
                },
                {
                    date: '2026-12-11',
                    arrivals: 0,
                    departures: 0,
                    inHouse: 0,
                    plannedArrivals: 1,
                    plannedDepartures: 0,
                },
            ],
            historyUnavailable: 2,
            housekeeping: [
                { status: 'dirty', label: 'Dirty', tone: 'warning', rooms: 4 },
                { status: 'clean', label: 'Clean', tone: 'success', rooms: 0 },
            ],
        }}
    />,
);

assert.match(populated, /10 Dec 2026/);
assert.match(populated, /11 Dec 2026/);
assert.match(populated, />Recorded arrivals</);
assert.match(populated, />Recorded departures</);
assert.match(populated, />Planned arrivals</);
assert.match(populated, />Planned departures</);
assert.match(populated, /Stays with incomplete recorded history:.*2/);
assert.match(
    populated,
    /Missing boundaries are unknown, not replaced by scheduled dates/,
);
assert.match(populated, />In-house</);
assert.match(populated, />Dirty</);
assert.match(populated, />Clean</);
assert.match(populated, />4</);
assert.equal((populated.match(/<table /g) ?? []).length, 2);
assert.doesNotMatch(populated, /No active rooms/);
console.log('Populated property operations: PASS');
