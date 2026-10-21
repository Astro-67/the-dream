import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    CalendarRoomRows,
    type CalendarReservation,
} from '@/features/reservations/calendar-rows';
import { TooltipProvider } from '@/components/ui/tooltip';

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
