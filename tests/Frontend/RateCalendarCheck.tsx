import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import RateCalendar from '@/pages/rates/calendar';

function render(canManage: boolean) {
    const dates = ['2026-10-09', '2026-10-10', '2026-10-11'];
    const props = {
        from: '2026-10-09',
        to: '2026-10-11',
        dates,
        businessDate: '2026-10-08',
        allRoomTypes: [{ id: 'deluxe', code: 'DLX', name: 'Deluxe' }],
        roomTypes: [
            {
                id: 'deluxe',
                code: 'DLX',
                name: 'Deluxe',
                sellable: Object.fromEntries(dates.map((date) => [date, 2])),
                available: Object.fromEntries(dates.map((date) => [date, 2])),
                outOfService: Object.fromEntries(
                    dates.map((date) => [date, 0]),
                ),
                rooms: [],
                unassigned: {},
                plans: [
                    {
                        id: 'breakfast',
                        code: 'BB',
                        name: 'Breakfast',
                        currency: 'USD',
                        derived: false,
                        prices: Object.fromEntries(
                            dates.map((date) => [date, 10000]),
                        ),
                        restrictions: Object.fromEntries(
                            dates.map((date) => [
                                date,
                                {
                                    min_stay: 3,
                                    closed_to_arrival: true,
                                    stop_sell: true,
                                },
                            ]),
                        ),
                    },
                ],
            },
        ],
        filters: { list: 'all', search: '' },
        canManage,
    };
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'rates/calendar',
                props,
                url: '/rate-calendar',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => <RateCalendar {...props} />}
        />,
    );
}

assert.match(
    render(true),
    /aria-label="Release stop-sell for Breakfast, Deluxe"[^>]*>Release stop-sell<\/button>/,
);
assert.doesNotMatch(render(false), /Release stop-sell/);
console.log('Rate calendar release stop-sell action and permissions: PASS');
