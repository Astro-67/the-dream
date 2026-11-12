import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import CheckoutReport from '@/pages/fiscal-receipting/checkout-report';
import ZraDocument, {
    type PrintedDocument,
} from '@/pages/fiscal-receipting/zra-document';

type Props = ComponentProps<typeof CheckoutReport>;

const row = {
    status: 'issued' as const,
    reason: null,
    retryUrl: null,
    refileUrl: null,
    printUrl: null,
    issuedAt: '2026-12-10T09:40:00Z',
    mode: 'sandbox' as const,
    portalUrl: null,
    qrSvg: null,
};
const base: Props = {
    status: 'sandbox',
    sandbox: true,
    rooms: 1,
    payingInstitution: null,
    summary: [
        {
            currency: 'USD',
            room: 22000,
            extras: 1500,
            vat: 0,
            levy: 400,
            charges: 23900,
            held: 0,
        },
    ],
    held: [],
    generalReceipts: [],
    serviceReceipts: [],
    guestReports: [],
    generalReceipt: null,
    printAllUrl: '/reservations/R-1042/fiscal-report/print',
    revealIdentityUrl: null,
    reservationUrl: '/reservations/R-1042',
    stay: {
        reservation_number: 'R-1042',
        room: '101',
        room_type: 'Deluxe Double',
        arrival: '2026-12-10',
        departure: '2026-12-12',
        nights: 2,
    },
    guests: ['Anna Berg', 'Ola Berg'],
};

const render = (props: Props, url = '/reservations/R-1042/fiscal-report') =>
    renderToStaticMarkup(
        <App
            initialPage={{
                component: 'fiscal-receipting/checkout-report',
                props: { ...props, tenant: { property: null } },
                url,
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => <CheckoutReport {...props} />}
        />,
    );

// The page has one fixed layout, whatever the state: summary, then every section.
const empty = render(base);
assert.match(empty, /ZRA documents/);
assert.doesNotMatch(empty, /Issued fiscal documents|Fiscal check-out report/i);
assert.match(empty, /aria-label="Summary"/);
assert.match(empty, /USD 220\.00/);
assert.match(empty, /Everything is receipted/);
assert.match(empty, /Print all/);
assert.doesNotMatch(
    empty,
    /General receipts \(|Guest receipts \(|Check-out reports \(/,
);

// protects=spec #249 "Sandbox banner shown once"; fails_when=the stamp repeats on a row.
const report = {
    ...row,
    number: 'REPORT-1',
    name: 'Private Guest',
    room: '101',
    amount: 23900,
    currency: 'USD',
    levy: 400,
    identity: {
        documentName: 'PASSPORT',
        country: 'GERMANY',
        documentNo: 'PRIVATE-DOCUMENT-172',
    },
    departedEarly: false,
    printUrl: '/print?document=r1',
    portalUrl: 'https://viewer.example/checkout-report/guest/REPORT-1',
    qrSvg: '<svg data-qr="REPORT-1"></svg>',
};
const full: Props = {
    ...base,
    rooms: 2,
    payingInstitution: { name: 'Ministry of Health', zrbNumber: 'WA2400117' },
    held: [
        {
            id: 'p1',
            description: 'LATE BAR',
            category: 'bar',
            quantity: 1,
            unit_price: 90000,
            discount: 0,
            amount: 90000,
            currency: 'TZS',
            room: '102',
            postedAt: '2026-12-10T16:40:00Z',
        },
    ],
    generalReceipts: [
        {
            ...row,
            number: 'XHE2104621',
            customer: 'Ministry of Health',
            items: 2,
            amount: 1070000,
            currency: 'TZS',
            printUrl: '/print?document=g1',
            portalUrl: 'https://viewer.example/receipt-form/XHE2104621',
            qrSvg: '<svg data-qr="XHE2104621"></svg>',
        },
    ],
    serviceReceipts: [
        {
            ...row,
            number: 'RECEIPT-172',
            description: 'Water',
            amount: 2000,
            currency: 'USD',
            guest: 'Amina Hassan',
            room: '101',
            printUrl: '/print?document=s1',
            portalUrl: 'https://viewer.example/receipt-form/RECEIPT-172',
            qrSvg: '<svg data-qr="RECEIPT-172"></svg>',
        },
    ],
    guestReports: [report],
    summary: [
        { ...base.summary[0], held: 0 },
        {
            currency: 'TZS',
            room: 0,
            extras: 1250000,
            vat: 163043,
            levy: 0,
            charges: 1250000,
            held: 90000,
        },
    ],
};
const html = render(full);
assert.equal((html.match(/TEST, NOT FISCAL/g) ?? []).length, 1);
for (const section of [
    /Not yet receipted \(1\)/,
    /General receipts \(1\)/,
    /Guest receipts \(1\)/,
    /Check-out reports \(1\)/,
]) {
    assert.match(html, section);
}
assert.match(html, /Paid by Ministry of Health \(WA2400117\)/);
assert.match(html, /group of 2 rooms/);
assert.match(html, /Amina Hassan · Room 101/);
// Money keeps its currency; currencies are never added together.
assert.match(html, /TZS 1,070,000/);
assert.match(html, /USD 20\.00/);
assert.match(html, /TZS 90,000/);
// Each row prints and opens its QR in a popover; the QR carries no guest name or document.
for (const url of ['g1', 's1', 'r1']) {
    assert.match(html, new RegExp(`href="/print\\?document=${url}"`));
}
const popovers = [
    ...html.matchAll(
        /<div[^>]*popover[^>]*>.*?<\/figcaption><\/figure><\/div>/g,
    ),
];
assert.equal(popovers.length, 3);
for (const [popover] of popovers) {
    assert.doesNotMatch(
        popover,
        /Private Guest|PASSPORT|GERMANY|PRIVATE-DOCUMENT/,
    );
    assert.match(popover, />Scan to verify with ZRA<\/a>/);
}
assert.doesNotMatch(html, /PRIVATE-DOCUMENT-172/);

// Status chips: Sending to ZRA, and Needs attention with ZRA's reason, Retry and Re-file.
const states = render({
    ...full,
    serviceReceipts: [],
    guestReports: [],
    generalReceipts: [
        {
            ...full.generalReceipts[0],
            number: null,
            status: 'sending',
            printUrl: null,
            portalUrl: null,
            qrSvg: null,
        },
        {
            ...full.generalReceipts[0],
            number: null,
            status: 'attention',
            reason: 'Invalid item',
            refileUrl: '/reservations/R-1042/vfms/m1/refile',
            retryUrl: '/reservations/R-1042/vfms/m2/retry',
            printUrl: null,
            portalUrl: null,
            qrSvg: null,
        },
    ],
});
assert.match(states, /Sending to ZRA/);
assert.match(states, /Needs attention/);
assert.match(states, /Invalid item/);
assert.match(states, />Re-file<\/button>/);
assert.match(states, />Retry<\/button>/);
assert.doesNotMatch(states, /data-qr=/);

// A document without a stored viewer link shows no QR or verify link.
const noLinks = render({
    ...full,
    guestReports: [
        { ...report, portalUrl: null, qrSvg: '<svg data-qr="x"></svg>' },
    ],
    serviceReceipts: [],
    generalReceipts: [],
});
assert.doesNotMatch(noLinks, /Scan to verify|data-qr=/);

// A waiting check-out says so; already-issued documents stay listed.
const waiting = render({ ...full, status: 'waiting' });
assert.match(waiting, /final check-out report is not complete/);
assert.match(waiting, /id="document-checkout_report-REPORT-1"/);
assert.match(waiting, /id="document-services_receipt-RECEIPT-172"/);

// Reveal document numbers stays, and a reveal is never put beside a QR.
assert.doesNotMatch(html, /Reveal document numbers/);
const reveal = render({
    ...full,
    revealIdentityUrl: '/reservations/R-1042/fiscal-report/reveal-identity',
});
assert.match(reveal, /Reveal document numbers for printing \(logged\)/);
const revealed = render({ ...full, identityRevealed: true });
assert.match(revealed, /Revealed identity documents/);
assert.match(revealed, /PRIVATE-DOCUMENT-172/);
assert.doesNotMatch(revealed, /Reveal document numbers for printing/);

// Document-correction controls keep working on the rows they apply to.
const correctionDocument = {
    id: 'issued-1',
    number: 'RECEIPT-172',
    type: 'services_receipt' as const,
    issued_at: '2026-12-10T09:00:00Z',
    amount: 2000,
    currency: 'USD',
    mode: 'sandbox' as const,
};
const withCorrection = (extra: object) =>
    render({
        ...full,
        documentCorrections: {
            'issued-1': {
                document: correctionDocument,
                submitUrl: '/issued-documents/issued-1/corrections',
                replacements: [],
                business: {},
                refusal: null,
                annotations: [],
                ...extra,
            },
        },
    });
assert.match(withCorrection({}), /Correct this document/);
assert.match(
    withCorrection({
        submitUrl: null,
        annotations: [
            {
                label: 'Replaced by',
                number: 'REPLACEMENT-172',
                url: '/replacement',
                delivery: 'delivered',
                decision: 'approved',
                refreshUrl: '/refresh',
            },
        ],
    }),
    /REPLACEMENT-172/,
);

// The print view: ZRA's receipt frame, one sheet per document.
const sheet = (over: Partial<PrintedDocument> = {}): PrintedDocument => ({
    id: 'g1',
    type: 'general_receipt',
    number: 'XHE2104621',
    mode: 'sandbox',
    currency: 'TZS',
    viewerUrl: 'https://viewer.example/receipt-form/XHE2104621',
    qrSvg: '<svg data-qr="XHE2104621"></svg>',
    issuedAt: '2026-11-12T10:40:00Z',
    annotations: [],
    business: {
        name: 'THE DREAM OF SAU INN BEACH HOTEL',
        street: 'Stone Town, Zanzibar',
        phone: '+255',
        tin: 'TIN-1',
        vrn: 'VRN-1',
        znumber: 'Z-1',
    },
    customer: 'OR-TAWALA ZA MIKOA',
    items: [
        {
            name: 'BREAKFAST AND LUNCH',
            quantity: 10,
            price: 555450,
            discount: 0,
            amount: 5554500,
        },
        {
            name: 'CONFERENCE HALL',
            quantity: 13,
            price: 427269,
            discount: 0,
            amount: 5554500,
        },
    ],
    rooms: [],
    taxExclusive: 9660000,
    tax: 1449000,
    levy: null,
    total: 11109000,
    ...over,
});
const printed = (documents: PrintedDocument[], sandbox = true) =>
    renderToStaticMarkup(
        <App
            initialPage={{
                component: 'fiscal-receipting/zra-document',
                props: {
                    tenant: { property: { timezone: 'Africa/Dar_es_Salaam' } },
                },
                url: '/reservations/R-1042/fiscal-report/print',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => (
                <ZraDocument
                    documents={documents}
                    sandbox={sandbox}
                    property={{ name: 'Sea Inn' }}
                    branding={{ logo_url: null }}
                    reservationNumber="R-1042"
                />
            )}
        />,
    );
const one = printed([sheet()]);
assert.match(one, /Start of a legal receipt/);
assert.match(one, /End of a legal receipt/);
assert.match(one, /THE DREAM OF SAU INN BEACH HOTEL/);
assert.match(one, /Z-number Z-1/);
assert.match(one, /OR-TAWALA ZA MIKOA/);
assert.match(one, /TZS 555,450/);
assert.match(one, /Tax exclusive/);
assert.match(one, /TZS 9,660,000/);
assert.match(one, /TZS 11,109,000/);
assert.match(one, /12 Nov 2026 13:40/);
assert.match(one, /data-qr="XHE2104621"/);
assert.match(one, /TEST, NOT FISCAL/);
const corrected = printed([
    sheet({
        annotations: [
            {
                label: 'Replaced by',
                number: 'REPLACEMENT-1',
                url: null,
                delivery: 'delivered',
                decision: 'approved',
                refreshUrl: '/corrections/refresh',
            },
        ],
    }),
]);
assert.match(corrected, /Replaced by/);
assert.match(corrected, /REPLACEMENT-1/);
assert.match(corrected, /Approved/);
assert.doesNotMatch(corrected, /corrections\/refresh/);
assert.doesNotMatch(
    printed([sheet({ mode: 'live' })], false),
    /TEST, NOT FISCAL/,
);
// Print all: one sheet per document, each a page of its own.
const all = printed([sheet(), sheet({ id: 'g2', number: 'XHE2104622' })]);
assert.equal((all.match(/<article/g) ?? []).length, 2);
assert.match(all, /break-after-page/);
assert.match(all, /Print all documents/);
// Figures ZRA never stored are unavailable, never invented.
const missing = printed([
    sheet({
        items: [],
        taxExclusive: null,
        tax: null,
        total: null,
        customer: null,
        qrSvg: null,
        viewerUrl: null,
    }),
]);
assert.match(missing, /Not available/);
assert.doesNotMatch(missing, /Scan to verify/);
// A check-out report uses the same frame, with rooms and the levy.
const checkout = printed([
    sheet({
        type: 'checkout_report',
        number: 'REPORT-1',
        currency: 'USD',
        customer: 'Anna Berg',
        items: [],
        rooms: [{ room: '101', days: 2, price: 11000, amount: 22000, tax: 0 }],
        taxExclusive: null,
        tax: 0,
        levy: 500,
        total: 22500,
    }),
]);
assert.match(checkout, /Start of a legal report/);
assert.match(checkout, /Infrastructure levy/);
assert.match(checkout, /USD 225\.00/);
assert.doesNotMatch(checkout, /PASSPORT|P1234567/);

console.log('ZRA documents: PASS');
