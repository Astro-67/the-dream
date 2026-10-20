import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { TooltipProvider } from '@/components/ui/tooltip';
import { CalendarRoomRows } from './calendar-rows';
import { formatDate } from '@/lib/utils';

test('reservation bars clip to the visible nights and leave departure free', () => {
    const html = renderToStaticMarkup(
        <TooltipProvider>
            <table>
                <tbody>
                    <CalendarRoomRows
                        dates={['2026-12-11', '2026-12-12', '2026-12-13']}
                        rooms={[
                            {
                                id: 'room',
                                number: '101',
                                reservations: [
                                    {
                                        reservation_number: 'R-1042',
                                        arrival: '2026-12-10',
                                        departure: '2026-12-13',
                                        guest_name: 'Staying Guest',
                                        source: 'phone',
                                        status: 'confirmed',
                                        url: '/reservations/R-1042',
                                    },
                                ],
                            },
                        ]}
                    />
                </tbody>
            </table>
        </TooltipProvider>,
    );
    assert.ok(html.includes('colSpan="2"'));
    assert.ok(html.includes('href="/reservations/R-1042"'));
    assert.ok(html.includes('Staying Guest'));
    assert.ok(html.includes('R-1042'));
    assert.ok(html.includes('Phone'));
    assert.ok(html.includes(`${formatDate('2026-12-13')}: unassigned`));
});

test('overstays remain visible through the business date with a text marker', () => {
    const html = renderToStaticMarkup(
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
                                        reservation_number: 'R-1042',
                                        arrival: '2026-12-09',
                                        departure: '2026-12-11',
                                        display_departure: '2026-12-13',
                                        is_overstay: true,
                                        guest_name: 'Overdue Guest',
                                        source: 'phone',
                                        status: 'checked_in',
                                        url: '/reservations/R-1042',
                                    },
                                ],
                            },
                        ]}
                    />
                </tbody>
            </table>
        </TooltipProvider>,
    );
    assert.ok(html.includes('Overstay'));
    assert.ok(html.includes('Overdue Guest'));
    assert.ok(html.includes(`${formatDate('2026-12-13')}: unassigned`));
    assert.ok(!html.includes(`${formatDate('2026-12-12')}: unassigned`));
});
