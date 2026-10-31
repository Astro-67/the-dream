import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentProps } from 'react';
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

function render(
    board: typeof roomBoard | null,
    overrides: Partial<ComponentProps<typeof Dashboard>> = {},
) {
    const props = {
        onboarding: null,
        today: null,
        collections: null,
        roomBoard: board,
        ...overrides,
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

const operational = render(roomBoard, {
    today: {
        businessDate: '2026-12-10',
        lists: [
            {
                key: 'arrivals',
                count: 3,
                href: '/reservations?list=arrivals&date=2026-12-10',
            },
            {
                key: 'departures',
                count: 0,
                href: '/reservations?list=departures&date=2026-12-10',
            },
            {
                key: 'in-house',
                count: 12,
                href: '/reservations?list=in-house&date=2026-12-10',
            },
        ],
    },
    collections: {
        period: 'today',
        from: '2026-12-10',
        to: '2026-12-10',
        received: { rows: [], totals: [] },
        href: '/reports/payments',
    },
});
const todaySection = operational.match(
    /<section aria-labelledby="today"[\s\S]*?<\/section>/,
)?.[0];
assert.ok(todaySection, 'Today section is visible');
assert.ok(
    operational.indexOf('aria-labelledby="today"') <
        operational.indexOf('aria-labelledby="collections-title"'),
    'Today precedes Collections',
);
assert.ok(
    operational.indexOf('aria-labelledby="today"') <
        operational.indexOf('aria-labelledby="room-status"'),
    'Today precedes the room board',
);
const cards = [...todaySection.matchAll(/<a\b[^>]*>[\s\S]*?<\/a>/g)].map(
    (match) => match[0],
);
assert.equal(cards.length, 3);
for (const [index, [key, label, count]] of [
    ['arrivals', 'Arrivals', 3],
    ['departures', 'Departures', 0],
    ['in-house', 'In house', 12],
].entries()) {
    const card = cards[index];
    assert.ok(
        card.includes(`href="/reservations?list=${key}&amp;date=2026-12-10"`),
        `${label} links to its business-date list`,
    );
    assert.ok(card.includes(`>${label}</span>`));
    assert.ok(card.includes(`>${count}</span>`));
    assert.match(card, /View list/);
}
console.log('Dashboard room board: PASS');
