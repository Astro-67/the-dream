import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ReportLayout from '@/features/reports/report-layout';
import Approvals from '@/pages/approvals/index';
import OpenBalances from '@/pages/billing/open-balances';
import CustomerAccounts from '@/pages/customer-accounts/index';
import TaxPreview from '@/pages/rates/tax-preview';
import TaxRules from '@/pages/rates/taxes';
import type { Paginated } from '@/types';

function page<Data>(data: Data[]): Paginated<Data> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: 50,
        from: data.length ? 1 : null,
        to: data.length || null,
        total: data.length,
        prev_page_url: null,
        next_page_url: null,
        links: [],
    };
}

function render(children: ReactNode) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'finance-workspace',
                url: '/approvals',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    tenant: {
                        property: {
                            id: 'property-1',
                            timezone: 'Africa/Dar_es_Salaam',
                        },
                    },
                },
            }}
            initialComponent={() => children}
        />,
    );
}

const approvals = render(
    <Approvals
        approvals={page([
            {
                id: 'approval-1',
                status: 'pending',
                folio_id: 'folio-1',
                requested_by: 'cashier-1',
                requester: 'Sample cashier',
                reservation_number: 'RES-000001',
                sourceUrl: '/reservations/RES-000001',
                type: 'write_off',
                amount: 1200,
                currency: 'USD',
                reason: 'Manager review',
                canResolve: false,
            },
        ])}
        thresholds={[{ currency: 'USD', amount: 0 }]}
        currencies={['USD']}
    />,
);
assert.match(approvals, /<h1[^>]*>Approvals<\/h1>/);
assert.match(approvals, /USD 12\.00/);
assert.match(approvals, /Another approver must decide your request/);
for (const decision of ['approve', 'reject']) {
    assert.match(
        approvals,
        new RegExp(
            `<button(?=[^>]*name="decision")(?=[^>]*value="${decision}")(?=[^>]*disabled)[^>]*>`,
        ),
    );
}
assert.match(approvals, /name="_property" value="property-1"/);
assert.match(approvals, /name="amount"[^>]*value="0"/);

const balances = render(
    <OpenBalances
        folios={page([
            {
                id: 'usd',
                currency: 'USD',
                balance: 1200,
                reservation_number: 'RES-000001',
                departure: '2026-11-18',
            },
            {
                id: 'tzs',
                currency: 'TZS',
                balance: -2500,
                reservation_number: 'RES-000002',
                departure: '2026-11-18',
            },
        ])}
    />,
);
assert.match(balances, /USD 12\.00/);
assert.match(balances, /TZS -2,500/);
assert.match(balances, /href="\/reservations\/RES-000002"/);
assert.match(
    render(<OpenBalances folios={page([])} />),
    /No open balances after departure/,
);

for (const canCreate of [false, true]) {
    const accounts = render(
        <CustomerAccounts
            accounts={page([])}
            filters={{}}
            canCreate={canCreate}
            currencies={['USD', 'TZS']}
            extraCategories={[]}
        />,
    );
    assert.match(accounts, /<h1[^>]*>Day use &amp; events<\/h1>/);
    assert.match(accounts, /No customer accounts match these filters/);
    assert.equal(accounts.includes('New day-use sale'), canCreate);
    assert.equal(accounts.includes('New event'), canCreate);
}

const preview = render(
    <TaxPreview
        charge={{ amount: '10000', currency: 'USD', date: '2026-11-19' }}
        currencies={['USD', 'TZS']}
        unpreviewable={null}
        breakdown={{
            currency: 'USD',
            net: 10000,
            tax: 1800,
            gross: 11800,
            lines: [
                {
                    name: 'VAT',
                    kind: 'percentage',
                    inclusive: false,
                    rate: 1800,
                    per_person_night: null,
                    amount: 1800,
                },
            ],
            unavailable: ['Tourism levy'],
        }}
    />,
);
assert.match(preview, /Tax breakdown/);
assert.match(preview, /Total charged/);
assert.match(preview, /USD 118\.00/);
assert.match(preview, /Not available in this currency/);
assert.match(preview, /<select[^>]*data-slot="select"/);
assert.match(
    render(<TaxRules taxRules={[]} currencies={['USD']} canManage={false} />),
    /No tax rules yet/,
);

const report = render(
    <ReportLayout
        title="Property reports"
        filters={{ from: '2026-11-01', to: '2026-11-19' }}
        refreshedAt="2026-11-19T09:00:00Z"
        action="/reports"
    >
        <p>Authorized report content</p>
    </ReportLayout>,
);
assert.match(report, /<h1[^>]*>Property reports<\/h1>/);
assert.match(report, /name="from"[^>]*value="2026-11-01"/);
assert.match(report, /name="to"[^>]*value="2026-11-19"/);
assert.match(report, /12:00/);
assert.match(report, /Africa\/Dar_es_Salaam/);
assert.match(report, /Authorized report content/);
assert.doesNotMatch(report, /p-4 md:p-6/);

console.log(
    'Finance workspace headings, permissions, currencies, tax breakdown and report context: PASS',
);
