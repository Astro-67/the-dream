import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import Show from '@/pages/night-audit/show';

const summary = renderToStaticMarkup(
    <App
        initialPage={{
            component: 'night-audit/show',
            url: '/night-audit/close',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            props: { errors: {} },
        }}
        initialComponent={() => (
            <Show
                close={{ business_date: '2026-12-10', actor: 'Automation' }}
                labels={{
                    title: 'Night audit',
                    charges: 'Room charges posted',
                }}
                charges={{
                    data: [
                        {
                            id: 'charge',
                            reservation_number: 'R-AUDIT-001',
                            unit_price: 11000,
                            amount: 12980,
                            currency: 'USD',
                            tax_lines: [{ name: 'VAT', amount: 1980 }],
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    prev_page_url: null,
                    next_page_url: null,
                    links: [],
                }}
            />
        )}
        resolveComponent={async () => Show}
    />,
);

assert.match(summary, />R-AUDIT-001</);
assert.match(summary, /USD 129\.80/);
assert.match(summary, /VAT: USD 19\.80/);
console.log('Night audit summary: PASS');
