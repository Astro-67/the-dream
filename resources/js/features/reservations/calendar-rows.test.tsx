import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { TooltipProvider } from '@/components/ui/tooltip';
import { CalendarRoomRows } from './calendar-rows';

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
    assert.ok(html.includes('2026-12-13: unassigned'));
});
