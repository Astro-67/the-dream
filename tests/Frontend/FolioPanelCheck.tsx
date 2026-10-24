import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { isValidElement, useState, type ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { PostingLink } from '@/features/billing/folio-corrections';
import {
    FolioPanel,
    ExtraChargeForm,
    type Folio,
    type Posting,
} from '@/features/billing/folio-panel';
import {
    FolioSummaryList,
    type FolioSummary,
} from '@/features/billing/folio-summary';
import type { Paginated } from '@/types';
import { PaymentsReceived } from '@/features/billing/payments-received';
import { PaymentForm } from '@/features/billing/payment-form';

const posting: Posting = {
    id: 'posting-in',
    type: 'transfer_in',
    category: 'laundry',
    description: null,
    reason: 'Company pays',
    related: {
        id: 'posting-out',
        type: 'transfer_out',
        category: 'laundry',
        folio_id: 'off-page-source',
    },
    cancelled_by: null,
    quantity: 1,
    unit_price: 100,
    net: 100,
    amount: 100,
    tax_lines: [],
    posted_at: '2026-12-10T09:00:00Z',
    actor: null,
};

const emptySummary: FolioSummary = {
    room: 0,
    extras: 0,
    vat: 0,
    levy: 0,
    other_taxes: 0,
    unclassified_taxes: 0,
    charges: 0,
    paid: 0,
    refunded: 0,
    written_off: 0,
    net_payments: 0,
    balance: 0,
    levy_unavailable: false,
};

const incoming = renderToStaticMarkup(<PostingLink posting={posting} />);
assert.match(incoming, /off-page-source/);
assert.match(incoming, /Copy off-page-source/);
const outgoing = renderToStaticMarkup(
    <PostingLink
        posting={{
            ...posting,
            type: 'charge',
            related: null,
            cancelled_by: {
                id: 'posting-out',
                type: 'transfer_out',
                folio_id: 'original-source',
                destination_folio_id: 'off-page-destination',
            },
        }}
    />,
);
assert.match(outgoing, /off-page-destination/);
assert.match(outgoing, /Copy off-page-destination/);
assert.doesNotMatch(outgoing, /original-source/);
const refunded = renderToStaticMarkup(
    <PostingLink
        posting={{
            ...posting,
            type: 'payment',
            related: null,
            reason: null,
            cancelled_by: {
                id: 'refund',
                type: 'refund',
                folio_id: 'f',
                destination_folio_id: null,
            },
        }}
    />,
);
assert.match(refunded, /Refunded/);
assert.doesNotMatch(refunded, /Moved out/);
console.log('Off-page transfer identity: PASS');

function pageOf<Item>(data: Item[]): Paginated<Item> {
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

const folios: Folio[] = Array.from({ length: 50 }, (_, index) => ({
    id: `folio-${index + 1}`,
    number: index + 1,
    currency: 'USD',
    balance: 0,
    postings: pageOf<Posting>([]),
    payments: pageOf<Posting>([]),
}));
folios[0].postings = pageOf([
    {
        ...posting,
        id: 'free-text',
        type: 'charge',
        category: 'other',
        description: 'Sunset boat trip',
    },
    {
        ...posting,
        id: 'legacy',
        type: 'charge',
        category: 'minibar',
        vfms_receipt_number: 'SBX-EXTRA-163',
    },
    { ...posting, id: 'room', type: 'charge', category: 'room' },
    {
        ...posting,
        id: 'payment',
        type: 'payment',
        category: 'payment',
        method: 'card',
    },
]);
folios[0].payments = pageOf([
    {
        ...posting,
        id: 'received',
        type: 'payment',
        category: 'payment',
        amount: -2500,
        reference: 'HIDDEN-PAYMENT',
    },
]);
const folioPage = {
    ...pageOf(folios),
    total: 51,
    last_page: 2,
    next_page_url: '/reservation?folios_page=2',
    links: [
        { url: null, label: 'Previous', active: false },
        { url: '/reservation?folios_page=1', label: '1', active: true },
        { url: '/reservation?folios_page=2', label: '2', active: false },
        { url: '/reservation?folios_page=2', label: 'Next', active: false },
    ],
};
function renderPanel(canRecordPayment = true, content?: ReactNode) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'FolioPanelCheck',
                url: '/reservation',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    tenant: { property: { id: 'property', timezone: 'UTC' } },
                    reservation: { reservation_number: 'R-1042' },
                    canRefund: true,
                    canWriteOff: true,
                    canOpenFolio: true,
                    canReverse: false,
                    canTransfer: false,
                    folioCurrencies: ['USD'],
                },
            }}
            initialComponent={() =>
                content ?? (
                    <FolioPanel
                        stay={{
                            reservation_number: 'R-1042',
                            guest: 'Amina Due',
                            room: '101',
                            room_type: 'Deluxe Double',
                            nights: 2,
                        }}
                        folios={folioPage}
                        totals={[
                            {
                                currency: 'USD',
                                ...emptySummary,
                                estimate: { nights: 2, amount: 20000 },
                                due: 20000,
                                status: 'unpaid',
                            },
                            {
                                currency: 'TZS',
                                ...emptySummary,
                                net_payments: 5000,
                                balance: -5000,
                                estimate: null,
                                due: -5000,
                                status: 'paid',
                            },
                        ]}
                        canPostCharge
                        canRecordPayment={canRecordPayment}
                        acceptedPaymentMethods={['card', 'other']}
                        extraCategories={['spa', 'other']}
                    />
                )
            }
        />,
    );
}
const panel = renderPanel();
// protects=the charge shows its VFMS receipt (#163); fails_when=receipt metadata is hidden; no_claim=production fiscal validity.
assert.match(panel, /VFMS receipt:/);
assert.match(panel, /Copy SBX-EXTRA-163/);
assert.match(panel, /Payments received \(1\)/);
assert.doesNotMatch(panel, /HIDDEN-PAYMENT/);
assert.equal((panel.match(/<form\b/g) ?? []).length, 0);
assert.match(panel, /Post an extra/);
assert.match(panel, /Refunds and write-offs/);
assert.match(panel, /Open another folio/);
assert.equal((panel.match(/Show breakdown for /g) ?? []).length, 2);
assert.doesNotMatch(
    panel,
    /name="category"|name="currency"|Room charges, net of tax/,
);
assert.match(panel, /Record payment for this USD folio/);
assert.match(panel, /aria-haspopup="dialog"/);
assert.doesNotMatch(renderPanel(false), /Record payment for this/);
assert.doesNotMatch(panel, /name="idempotency_key"[^>]*payment/);
// Test the hidden breakdown directly rather than dropping its coverage.
const breakdown = renderToStaticMarkup(
    <FolioSummaryList
        summary={emptySummary}
        currency="USD"
        estimate={{ nights: 2, amount: 20000 }}
    />,
);
// A zero balance with nights still to charge is not called settled.
assert.match(breakdown, /Balance of posted charges/);
assert.doesNotMatch(breakdown, /Settled/);
assert.match(breakdown, /Not yet charged: 2 nights/);
// ADR 0015: at a VFMS property Thedream's tax is an estimate until VFMS answers at check-out.
const estimated = renderToStaticMarkup(
    <FolioSummaryList
        summary={emptySummary}
        currency="USD"
        taxSource="estimated"
    />,
);
assert.match(estimated, /Estimated tax: VAT/);
assert.match(estimated, /Estimated tax: Infrastructure levy/);
const filed = renderToStaticMarkup(
    <FolioSummaryList summary={emptySummary} currency="USD" taxSource="vfms" />,
);
assert.match(filed, /VAT \(VFMS\)/);
assert.match(filed, /Infrastructure levy \(VFMS\)/);
assert.doesNotMatch(filed, /Estimated tax/);
assert.doesNotMatch(breakdown, /Estimated tax|VFMS/);
assert.match(panel, /Amina Due/);
assert.match(panel, /Deluxe Double/);
assert.match(panel, />Unpaid</);
assert.match(panel, /Due includes an estimate of [^<]*200[^<]* for 2 nights/);
// A credit reads as Paid with the credit amount, never a negative Due.
assert.match(panel, />Paid</);
assert.match(panel, /Credit/);
assert.match(panel, /reservations\/R-1042\/statement/);
assert.match(panel, /<span class="font-mono">R-1042<\/span>/);
assert.equal((panel.match(/aria-label="View folio /g) ?? []).length, 50);
assert.match(panel, /folios_page=2/);
assert.match(panel, /Sunset boat trip/);
assert.match(panel, /Minibar/);
assert.match(panel, /Room charge/);
assert.match(panel, /Payment/);
const extra = renderPanel(
    true,
    <ExtraChargeForm folio={folios[0]} categories={['spa', 'other']} />,
);
assert.equal((extra.match(/<form\b/g) ?? []).length, 1);
assert.match(extra, /Item name/);
assert.match(extra, /VAT-inclusive unit price/);
assert.match(extra, /name="category"/);
// Category options are the server's list, labelled here.
assert.match(extra, /<option value="spa">Spa<\/option>/);
assert.doesNotMatch(extra, /<option value="restaurant">/);
console.log(
    'Folio actions start closed; extracted extra form retains category coverage: PASS',
);

function changePaymentField(
    node: ReactNode,
    field: string,
    value: string,
): boolean {
    if (Array.isArray(node)) {
        return node.some((child) => changePaymentField(child, field, value));
    }
    if (
        !isValidElement<{
            id?: string;
            children?:
                | ReactNode
                | ((state: {
                      errors: Record<string, string>;
                      processing: boolean;
                  }) => ReactNode);
            onChange?: (event: { target: { value: string } }) => void;
        }>(node)
    )
        return false;
    if (node.props.id === `payment-folio-payment-${field}`) {
        assert.ok(node.props.onChange);
        node.props.onChange({ target: { value } });
        return true;
    }
    return changePaymentField(
        typeof node.props.children === 'function'
            ? node.props.children({ errors: {}, processing: false })
            : node.props.children,
        field,
        value,
    );
}

function paymentForm(
    due: number,
    currency = 'USD',
    amount?: string,
    channelCollectedAmount: number | null = null,
    methodChanges: string[] = [],
) {
    function PaymentCheck() {
        const [changed, setChanged] = useState(0);
        const form = PaymentForm({
            folio: { id: 'folio-payment', currency },
            total: {
                ...emptySummary,
                currency,
                due,
                status: 'unpaid',
                estimate: null,
            },
            channelCollectedAmount,
            acceptedMethods: ['card', 'other', 'channel_collected'],
        });
        const changes = [
            ...(amount === undefined
                ? []
                : [{ field: 'amount', value: amount }]),
            ...methodChanges.map((value) => ({ field: 'method', value })),
        ];
        if (changed < changes.length) {
            const change = changes[changed];
            assert.ok(changePaymentField(form, change.field, change.value));
            setChanged(changed + 1);
        }
        return form;
    }
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'PaymentFormCheck',
                url: '/reservation',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    tenant: { property: { id: 'property' } },
                },
            }}
            initialComponent={PaymentCheck}
        />,
    );
}
const payment = paymentForm(20000);
assert.match(payment, /<option value="card" selected="">/);
assert.match(payment, /<option value="other">Other<\/option>/);
assert.doesNotMatch(payment, /<option value="bank_transfer"/);
assert.doesNotMatch(payment, /<option value="cash"/);
assert.match(payment, /name="amount" value="20000"/);
assert.match(payment, /value="200"/);
assert.match(payment, /name="card_last_four"/);
assert.match(payment, /name="card_brand"/);
assert.match(payment, /name="reference"/);
assert.match(payment, /name="idempotency_key"/);
assert.match(payment, /action="[^"]*folios\/folio-payment\/payments"/);
assert.match(paymentForm(0), /name="amount" value=""/);
assert.match(paymentForm(-5000), /name="amount" value=""/);
assert.match(paymentForm(25000, 'TZS'), /value="25000"/);
console.log('Payment defaults and existing fields: PASS');
assert.match(payment, /After this payment/);
assert.match(payment, /Due: USD 0\.00/);
assert.match(payment, />Paid</);
const partial = paymentForm(20000, 'USD', '50');
assert.match(partial, /Due: USD 150\.00/);
assert.match(partial, />Partially paid</);
assert.doesNotMatch(partial, /This exceeds Due/);
const overpayment = paymentForm(20000, 'USD', '250');
assert.match(overpayment, /Credit: USD 50\.00/);
assert.match(overpayment, />Paid</);
assert.match(overpayment, /This exceeds Due/);
assert.doesNotMatch(overpayment, /<button[^>]* disabled=/);
assert.doesNotMatch(overpayment, /max="200"/);
assert.doesNotMatch(paymentForm(20000, 'USD', ''), /After this payment/);
assert.doesNotMatch(paymentForm(20000, 'USD', '-1'), /After this payment/);
assert.match(paymentForm(0, 'USD', '5'), /Credit: USD 5\.00/);
assert.match(paymentForm(-5000, 'USD', '5'), /Credit: USD 55\.00/);
assert.match(paymentForm(25000, 'TZS', '5000'), /Due: TZS 20,000/);
assert.doesNotMatch(payment, /<option value="channel_collected"/);
const channel = paymentForm(20000, 'USD', undefined, 24000, [
    'channel_collected',
]);
assert.match(channel, /Credit: USD 40\.00/);
assert.doesNotMatch(channel, /name="amount"/);
assert.match(channel, /This exceeds Due/);
const restoredAmount = paymentForm(20000, 'USD', '50', 24000, [
    'channel_collected',
    'card',
]);
assert.match(restoredAmount, /name="amount" value="5000"/);
assert.match(restoredAmount, /Due: USD 150\.00/);
console.log('Live payment preview and non-blocking credit warning: PASS');

const paymentsReceived = renderToStaticMarkup(
    <PaymentsReceived
        payments={pageOf([
            {
                ...posting,
                id: 'deposit-151',
                type: 'payment',
                category: 'deposit',
                method: 'bank_transfer',
                amount: -2500,
                actor: 'Front desk agent',
                reference: 'BANK-151',
                receipt: { id: 'receipt-151', number: 'RCT-000151' },
            },
            {
                ...posting,
                type: 'refund',
                category: 'refund',
                amount: 500,
                method: 'card',
            },
        ])}
        currency="USD"
        timezone="UTC"
    />,
);
assert.match(paymentsReceived, /Payments received/);
assert.match(paymentsReceived, /Deposit/);
assert.match(paymentsReceived, /Bank transfer/);
assert.match(paymentsReceived, /USD 25\.00/);
assert.match(paymentsReceived, /Refund/);
assert.match(paymentsReceived, /USD -5\.00/);
assert.match(paymentsReceived, /Front desk agent/);
assert.match(paymentsReceived, /BANK-151/);
assert.match(paymentsReceived, /receipts\/receipt-151/);
assert.match(paymentsReceived, /RCT-000151/);
assert.equal(
    renderToStaticMarkup(
        <PaymentsReceived
            payments={pageOf([])}
            currency="USD"
            timezone="UTC"
        />,
    ),
    '',
);
