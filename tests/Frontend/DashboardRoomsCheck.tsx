import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import Dashboard from '@/pages/dashboard';

const roomBoard = {
    businessDate: '2026-12-10',
    housekeepingHref: '/housekeeping',
    calendarHref: '/rate-calendar',
    rooms: {
        data: [
            {
                id: 'room-101',
                number: '101',
                roomType: 'Deluxe Double',
                status: 'reserved',
                statusLabel: 'Reserved',
                tone: 'info' as const,
                readinessLabel: 'Inspection required',
                readinessTone: 'inspection' as const,
            },
        ],
        current_page: 1,
        last_page: 2,
        per_page: 50,
        from: 1,
        to: 50,
        total: 51,
        links: [],
        prev_page_url: null,
        next_page_url: '/dashboard?page=2',
    },
};

function render(board: typeof roomBoard | null) {
    const props = {
        onboarding: null,
        today: null,
        collections: null,
        roomBoard: board,
    };
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'dashboard',
                props: {
                    ...props,
                    auth: { user: { name: 'Neema' }, permissions: [] },
                    tenant: { property: { name: 'Stone Town Inn' } },
                },
                url: '/dashboard',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => <Dashboard {...props} />}
        />,
    );
}

const html = render(roomBoard);
assert.match(html, /Room status/);
assert.match(html, /101/);
assert.match(html, /Deluxe Double/);
assert.match(html, /Reserved/);
assert.match(html, /Inspection required/);
assert.match(html, /href="\/housekeeping"/);
assert.match(html, /href="\/rate-calendar"/);
assert.match(html, /href="\/dashboard\?page=2"/);
assert.match(html, /Business date/);
const empty = render({
    ...roomBoard,
    rooms: { ...roomBoard.rooms, data: [], total: 0, last_page: 1 },
});
assert.match(empty, /No active rooms/);
assert.doesNotMatch(empty, /<table/);
assert.doesNotMatch(render(null), /Room status|\/housekeeping|\/rate-calendar/);
console.log('Dashboard room board: PASS');
