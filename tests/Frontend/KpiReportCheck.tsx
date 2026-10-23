import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import KpiReport from '@/features/reports/kpi-report';
import Payments from '@/pages/payments';

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

const reservationPayments = renderToStaticMarkup(
    <App
        initialPage={{
            component: 'Payments',
            url: '/payments',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            props: {
                errors: {},
                tenant: { property: { timezone: 'UTC' } },
            },
        }}
        initialComponent={() => (
            <Payments
                filters={{
                    from: '2026-06-01',
                    to: '2026-06-03',
                    period: 'custom',
                    view: 'received',
                    settled: false,
                    reservation: 'reservation-151',
                }}
                reservationNumber="R-000151"
                received={{ rows: [], totals: [] }}
                payments={null}
                folios={null}
                refreshedAt="2026-06-03T09:00:00Z"
                cashierShiftHistoryUrl={null}
            />
        )}
    />,
);
assert.match(reservationPayments, /name="reservation" value="reservation-151"/);
assert.match(reservationPayments, /R-000151/);
assert.match(reservationPayments, /Clear reservation filter/);
assert.match(
    reservationPayments,
    /period=today[^"]*reservation=reservation-151/,
);
assert.match(
    reservationPayments,
    /view=outstanding[^"]*reservation=reservation-151/,
);
console.log('Reservation payment report filters: PASS');
