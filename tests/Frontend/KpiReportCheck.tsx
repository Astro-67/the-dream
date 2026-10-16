import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import KpiReport from '@/features/reports/kpi-report';

const group = renderToStaticMarkup(
    <KpiReport
        group
        kpis={{
            soldRoomNights: 0,
            sellableRoomNights: 0,
            occupancy: null,
            currencies: [
                {
                    currency: 'TZS',
                    bookedRoomValue: 0,
                    soldRoomNights: 0,
                    sellableRoomNights: 0,
                    bookedAdr: null,
                    bookedRevpar: null,
                },
                {
                    currency: 'USD',
                    bookedRoomValue: 22000,
                    soldRoomNights: 2,
                    sellableRoomNights: 10,
                    bookedAdr: 11000,
                    bookedRevpar: 2200,
                },
            ],
        }}
    />,
);

assert.equal((group.match(/Not applicable/g) ?? []).length, 3);
assert.match(group, /not averages of property ratios/);
assert.match(group, /properties reporting that currency/);
assert.match(group, /not the average price of rooms sold in that currency/);
assert.match(group, />TZS</);
assert.match(group, />USD</);
assert.doesNotMatch(
    renderToStaticMarkup(
        <KpiReport
            kpis={{
                soldRoomNights: 1,
                sellableRoomNights: 1,
                occupancy: 1,
                currencies: [],
            }}
        />,
    ),
    /not averages/,
);
console.log('Group KPI report: PASS');
