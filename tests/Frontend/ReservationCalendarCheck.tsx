import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    CalendarRoomRows,
    type CalendarReservation,
} from '@/features/reservations/calendar-rows';
import { TooltipProvider } from '@/components/ui/tooltip';
import { App } from '@inertiajs/react';
import { Dialog } from '@/components/ui/dialog';
import { CalendarOutOfOrder } from '@/features/rates/calendar-out-of-order';
import { OutOfService } from '@/features/inventory/out-of-service';

const departure = renderToStaticMarkup(
    <TooltipProvider>
        <table>
            <tbody>
                <CalendarRoomRows
                    dates={['2026-12-12', '2026-12-13']}
                    rooms={[
                        {
                            id: 'room',
                            number: '101',
                            reservations: [
                                {
                                    reservation_number: 'DEPARTING',
                                    arrival: '2026-12-10',
                                    departure: '2026-12-12',
                                    guest_name: 'Departing Guest',
                                    source: 'phone',
                                    status: 'checked_in',
                                    url: '/reservations/departing',
                                },
                            ],
                        },
                    ]}
                />
            </tbody>
        </table>
    </TooltipProvider>,
);
assert.match(departure, /href="\/reservations\/departing"/);
assert.match(departure, /Departure/);
assert.match(departure, /Departing Guest/);
assert.doesNotMatch(departure, /colSpan=/);
assert.doesNotMatch(departure, /<a[^>]*bg-primary-tint/);
assert.match(departure, /12 Dec 2026: unassigned/);
assert.match(departure, /13 Dec 2026: unassigned/);
console.log('Departure-day marker without an occupied night: PASS');

const overlapping = renderToStaticMarkup(
    <TooltipProvider>
        <table>
            <tbody>
                <CalendarRoomRows
                    dates={['2026-12-12']}
                    rooms={[
                        {
                            id: 'room',
                            number: '101',
                            reservations: [
                                {
                                    reservation_number: 'OVERSTAY',
                                    arrival: '2026-12-09',
                                    departure: '2026-12-11',
                                    display_departure: '2026-12-13',
                                    is_overstay: true,
                                    guest_name: 'Overdue Guest',
                                    source: 'phone',
                                    status: 'checked_in',
                                    url: '/reservations/overstay',
                                },
                                {
                                    reservation_number: 'ARRIVING',
                                    arrival: '2026-12-12',
                                    departure: '2026-12-14',
                                    guest_name: 'Arriving Guest',
                                    source: 'direct',
                                    status: 'confirmed',
                                    url: '/reservations/arriving',
                                },
                            ],
                        },
                    ]}
                />
            </tbody>
        </table>
    </TooltipProvider>,
);
assert.match(overlapping, /href="\/reservations\/overstay"/);
assert.match(overlapping, /href="\/reservations\/arriving"/);
assert.match(overlapping, /Overstay/);
assert.match(overlapping, /Overdue Guest/);
assert.match(overlapping, /Arriving Guest/);
assert.match(overlapping, /rowSpan="2"/);
assert.equal((overlapping.match(/<tr /g) ?? []).length, 2);
console.log('Overstay and conflicting arrival render independently: PASS');

const consecutive: CalendarReservation[] = [
    {
        reservation_number: 'DEPARTING',
        arrival: '2026-12-10',
        departure: '2026-12-12',
        guest_name: 'Departing Guest',
        source: 'phone',
        status: 'checked_out',
        url: '/reservations/departing',
    },
    {
        reservation_number: 'ARRIVING',
        arrival: '2026-12-12',
        departure: '2026-12-14',
        guest_name: 'Arriving Guest',
        source: 'direct',
        status: 'confirmed',
        url: '/reservations/arriving',
    },
];
const turnover = renderToStaticMarkup(
    <TooltipProvider>
        <table>
            <tbody>
                <CalendarRoomRows
                    dates={['2026-12-11', '2026-12-12', '2026-12-13']}
                    rooms={[
                        {
                            id: 'room',
                            number: '101',
                            reservations: consecutive,
                        },
                    ]}
                />
            </tbody>
        </table>
    </TooltipProvider>,
);
assert.equal((turnover.match(/<tr /g) ?? []).length, 1);
assert.match(
    turnover,
    /colSpan="2"[^>]*>.*Departure: Departing Guest.*href="\/reservations\/arriving"/,
);
console.log(
    'Same-day turnover shares a row without an extra occupied night: PASS',
);

for (const canManageOutOfOrder of [true, false]) {
    const html = renderToStaticMarkup(
        <TooltipProvider>
            <table>
                <tbody>
                    <CalendarRoomRows
                        dates={['2026-12-11', '2026-12-12', '2026-12-13']}
                        businessDate="2026-12-11"
                        onOutOfOrder={() => {}}
                        rooms={[
                            {
                                id: 'room',
                                number: '101',
                                reservations: consecutive,
                                canManageOutOfOrder,
                                outOfServicePeriods: [
                                    {
                                        id: 'period',
                                        startsOn: '2026-12-10',
                                        returnsOn: '2026-12-13',
                                        reason: 'Leaking shower',
                                        started: true,
                                    },
                                ],
                            },
                        ]}
                    />
                </tbody>
            </table>
        </TooltipProvider>,
    );
    assert.match(html, /Out of order/);
    assert.match(html, /Leaking shower/);
    assert.match(html, /colSpan="2"/);
    assert.match(html, /href="\/reservations\/arriving"/);
    if (canManageOutOfOrder) {
        assert.match(html, /aria-label="Return room 101 to service/);
        assert.match(html, /aria-label="Take room 101 out of order/);
    } else {
        assert.doesNotMatch(html, /<button/);
    }
    assert.doesNotMatch(html, /out.of.service/i);
}
console.log(
    'Out-of-order room periods remain visible alongside stays, read-only for front desk: PASS',
);

for (const startsOn of [undefined, '2026-12-10', '2026-12-15']) {
    const Page = () => (
        <Dialog open>
            <OutOfService roomId="room" periods={[]} today="2026-12-12" />
            <CalendarOutOfOrder
                editing={{
                    room: { id: 'room', number: '101', reservations: [] },
                    date: '2026-12-12',
                    period: startsOn
                        ? {
                              id: 'period',
                              startsOn,
                              returnsOn: '2026-12-20',
                              reason: 'Leaking shower',
                              started: startsOn <= '2026-12-12',
                          }
                        : undefined,
                }}
                businessDate="2026-12-12"
                onDone={() => {}}
            />
        </Dialog>
    );
    const html = renderToStaticMarkup(
        <App
            initialPage={{
                component: 'calendar',
                url: '/rate-calendar',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: { errors: {}, tenant: { property: { id: 'property' } } },
            }}
            initialComponent={Page}
            resolveComponent={async () => Page}
        />,
    );
    assert.match(html, /name="_property" value="property"/);
    assert.match(html, /name="calendar" value="1"/);
    assert.doesNotMatch(html.replace(/<[^>]+>/g, ''), /out.of.service/i);
    if (startsOn) {
        assert.match(html, /Return room 101 to service/);
        assert.match(html, /Leaking shower/);
        assert.match(
            html,
            new RegExp(
                `name="returns_on" value="${startsOn > '2026-12-12' ? startsOn : '2026-12-12'}"`,
            ),
        );
    } else {
        assert.match(html, /Take room 101 out of order/);
        assert.match(html, /name="starts_on"[^>]*value="2026-12-12"/);
        assert.match(html, /name="returns_on"[^>]*value="2026-12-13"/);
        assert.match(html, /required[^>]*name="reason"/);
    }
}
console.log(
    'Calendar out-of-order dialog submits scoped dates for taking and returning rooms: PASS',
);
