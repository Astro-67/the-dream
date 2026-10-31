import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import RateCalendar from '@/pages/rates/calendar';

function render(canManage: boolean) {
    const props = {
        from: '2026-10-09',
        to: '2026-10-11',
        dates: ['2026-10-09', '2026-10-10', '2026-10-11'],
        businessDate: '2026-10-08',
        allRoomTypes: [{ id: 'deluxe', code: 'DLX', name: 'Deluxe' }],
        roomTypes: [
            {
                id: 'deluxe',
                code: 'DLX',
                name: 'Deluxe',
                sellable: {},
                outOfService: {},
                rooms: [],
                unassigned: {},
                plans: [
                    {
                        id: 'breakfast',
                        code: 'BB',
                        name: 'Breakfast',
                        currency: 'USD',
                        derived: false,
                        prices: {},
                        restrictions: Object.fromEntries(
                            ['2026-10-09', '2026-10-10', '2026-10-11'].map(
                                (date) => [
                                    date,
                                    {
                                        min_stay: 3,
                                        closed_to_arrival: true,
                                        stop_sell: true,
                                    },
                                ],
                            ),
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
