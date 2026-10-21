import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import { CalendarRoomRows } from '@/features/reservations/calendar-rows';
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
