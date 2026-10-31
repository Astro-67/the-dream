import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import Show from '@/pages/subscription/invoice';

const invoice = {
    id: 'inv-1',
    number: 'SAAS-000042',
    status: 'open' as const,
    statusLabel: 'Open',
    overdue: false,
    periodStartsAt: '2026-11-01T00:00:00Z',
    periodEndsAt: '2026-12-01T00:00:00Z',
    dueAt: '2026-11-01T00:00:00Z',
    issuedAt: '2026-10-29T00:00:00Z',
    amount: 4900,
    balance: 4900,
    currency: 'USD',
    plan: 'Standard',
};
const billing = {
    business_name: 'Thedream Ltd',
    address: 'Mlandege Street, Zanzibar',
    phone: '+255 777 000 111',
    email: 'billing@thedream.test',
    tin: '100-200-300',
    vrn: '40-001234-X',
    organization_name: 'Spice Island Hotels',
    accounts: [
        {
            type: 'bank' as const,
            bank: 'CRDB Bank',
            branch: 'Stone Town',
            swift: 'CORUTZTZ',
            account_name: 'Thedream Ltd',
            account_number: '0150123456700',
        },
        {
            type: 'mobile_money' as const,
            provider: 'M-Pesa',
            account_name: 'Thedream Ltd',
            account_number: '0777000111',
        },
    ],
};
const render = (details: typeof billing | null) => {
    const props = { invoice, billing: details, payments: [] };

    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'subscription/invoice',
                props,
                url: '/subscription/invoices/inv-1',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => <Show {...props} />}
        />,
    );
};

const html = render(billing);
for (const text of [
    'Thedream Ltd',
    'Mlandege Street, Zanzibar',
    'billing@thedream.test',
    'TIN 100-200-300',
    'VRN 40-001234-X',
    'Spice Island Hotels',
    'CRDB Bank, Stone Town',
    'CORUTZTZ',
    '0150123456700',
    'M-Pesa',
    '0777000111',
    'SAAS-000042',
    'Use the invoice number as the payment reference.',
]) {
    assert.ok(html.includes(text), `invoice must show ${text}`);
}

const old = render(null);
assert.ok(old.includes('SAAS-000042'));
assert.ok(!old.includes('How to pay'));
assert.ok(!old.includes('Use the invoice number'));
console.log('SaaS invoice: PASS');
