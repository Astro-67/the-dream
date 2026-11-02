import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import CheckoutReport from '@/pages/fiscal-receipting/checkout-report';

type Props = ComponentProps<typeof CheckoutReport>;

const base: Props = {
    status: 'sandbox',
    report: {
        number: 'SBX-1A2B',
        currency: 'USD',
        room: 22000,
        extras: 1500,
        vat: 0,
        levy: 400,
        total: 23900,
    },
    portalUrl: null,
    qrSvg: null,
    guestReports: [],
    business: null,
    needsAttention: false,
    revealIdentityUrl: null,
    serviceReceipts: [],
    stay: {
        reservation_number: 'R-1042',
        room: '101',
        room_type: 'Deluxe Double',
        arrival: '2026-12-10',
        departure: '2026-12-12',
        nights: 2,
    },
    guests: ['Anna Berg', 'Ola Berg'],
    property: {
        name: 'Sea Inn',
        address_line: 'Stone Town',
        city: 'Zanzibar',
        phone: '+255',
        email: 'desk@example.test',
    },
    branding: { logo_url: null, accent_colour: null },
};

const render = (props: Props) =>
    renderToStaticMarkup(
        <App
            initialPage={{
                component: 'fiscal-receipting/checkout-report',
                props: { ...props, tenant: { property: null } },
                url: '/reservations/R-1042/fiscal-report',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => <CheckoutReport {...props} />}
        />,
    );

// A Sandbox report is stamped and carries no portal QR.
const sandbox = render(base);
assert.match(sandbox, /FISCAL CHECK-OUT REPORT/);
assert.match(sandbox, /TEST, NOT FISCAL/);
assert.match(sandbox, /SBX-1A2B/);
assert.match(sandbox, /Anna Berg, Ola Berg/);
assert.match(sandbox, /USD 239\.00/);
assert.doesNotMatch(sandbox, /VFMS QR code/);

// A Live report shows the portal QR and no Sandbox stamp.
const live = render({
    ...base,
    status: 'live',
    report: { ...base.report!, number: 'CO-778' },
    portalUrl: 'https://portalvfms.zanrevenue.org/checkout-report/guest/CO-778',
    qrSvg: '<svg data-qr="1"></svg>',
});
assert.match(
    live,
    /VFMS QR code for https:\/\/portalvfms\.zanrevenue\.org\/checkout-report\/guest\/CO-778/,
);
assert.match(live, /data-qr="1"/);
assert.doesNotMatch(live, /TEST, NOT FISCAL/);
assert.match(
    live,
    /<figcaption[^>]*>.*href="https:\/\/portalvfms\.zanrevenue\.org\/checkout-report\/guest\/CO-778"[^>]*>Scan to verify with ZRA<\/a>.*CO-778.*<\/figcaption>/,
);

// protects=each recorded guest report is printable with its own verification link (#172); fails_when=one report or the test stamp disappears; no_claim=physical A4 layout.
const recordedProps: Props = {
    ...base,
    business: {
        businessName: 'ZRA Test Hotel',
        street: 'Test Street',
        tinNumber: 'TEST-TIN',
        vrnNumber: 'TEST-VRN',
        znumber: 'TEST-Z',
    },
    guestReports: ['REPORT-1', 'REPORT-2'].map((number) => ({
        number,
        name: `Guest ${number}`,
        identity: {
            documentName: 'PASSPORT',
            country: 'GERMANY',
            documentNo: 'PRIVATE-DOCUMENT-172',
        },
        departedEarly: false,
        portalUrl: `https://viewer.example/checkout-report/guest/${number}`,
        qrSvg: `<svg data-qr="${number}"></svg>`,
    })),
    serviceReceipts: [
        {
            number: 'RECEIPT-172',
            description: 'Water',
            amount: 2000,
            mode: 'sandbox',
            portalUrl: 'https://viewer.example/receipt-form/RECEIPT-172',
            qrSvg: '<svg data-qr="RECEIPT-172"></svg>',
        },
    ],
};
const recorded = render(recordedProps);
assert.match(recorded, /ZRA Test Hotel/);
assert.match(recorded, /TIN TEST-TIN/);
assert.match(recorded, /TEST, NOT FISCAL/);
assert.match(recorded, /data-qr="REPORT-1"/);
assert.match(recorded, /data-qr="REPORT-2"/);
assert.match(recorded, /RECEIPT-172/);
assert.match(recorded, /data-qr="RECEIPT-172"/);
assert.match(
    recorded,
    /href="https:\/\/viewer\.example\/receipt-form\/RECEIPT-172"/,
);
assert.doesNotMatch(recorded, /Zanzibar Revenue Authority|zra-logo/);
assert.doesNotMatch(recorded, /Guest REPORT-[12]|PASSPORT|GERMANY/);
assert.doesNotMatch(recorded, /PRIVATE-DOCUMENT-172/);
const verificationBlocks = [
    ...recorded.matchAll(/<figure\b[^>]*>.*?<\/figure>/g),
].map(([figure]) => figure);
assert.equal(verificationBlocks.length, 3);
for (const number of ['REPORT-1', 'REPORT-2', 'RECEIPT-172']) {
    const block = verificationBlocks.find((figure) =>
        figure.includes(`data-qr="${number}"`),
    );
    assert.ok(block, `${number} has its own verification block`);
    const path =
        number === 'RECEIPT-172' ? 'receipt-form' : 'checkout-report/guest';
    assert.ok(
        block.includes(`href="https://viewer.example/${path}/${number}"`),
    );
    assert.match(block, />Scan to verify with ZRA<\/a>/);
    assert.match(block, /<figcaption\b/);
    assert.ok(block.slice(block.indexOf('<figcaption')).includes(number));
    assert.doesNotMatch(
        block,
        /Guest REPORT|PASSPORT|GERMANY|PRIVATE-DOCUMENT/,
    );
}

const revealControl = render({
    ...base,
    revealIdentityUrl: '/reservations/R-1042/fiscal-report/reveal-identity',
});
assert.match(revealControl, /Reveal document numbers for printing \(logged\)/);
assert.match(revealControl, /method="post"/);
const revealed = render({
    ...base,
    identityRevealed: true,
    guestReports: [
        {
            number: 'REPORT-1',
            name: 'Anna Berg',
            identity: {
                documentName: 'PASSPORT',
                documentNo: 'REVEALED-DOCUMENT-172',
                country: 'GERMANY',
            },
            departedEarly: false,
            portalUrl: null,
            qrSvg: null,
        },
    ],
});
assert.match(revealed, /REVEALED-DOCUMENT-172/);
assert.doesNotMatch(revealed, /Reveal document numbers for printing/);
assert.match(revealed, /Revealed identity documents/);
for (const [figure] of revealed.matchAll(/<figure\b[^>]*>.*?<\/figure>/g)) {
    assert.doesNotMatch(figure, /Anna Berg|PASSPORT|GERMANY|REVEALED-DOCUMENT/);
}

const withoutDestinations: Props = {
    ...base,
    qrSvg: '<svg data-qr="orphan-fallback"></svg>',
    guestReports: [
        {
            number: 'NO-LINK-GUEST',
            name: 'Private Guest',
            identity: null,
            departedEarly: true,
            portalUrl: null,
            qrSvg: '<svg data-qr="orphan-guest"></svg>',
        },
    ],
    serviceReceipts: [
        {
            number: 'NO-LINK-RECEIPT',
            description: 'Water',
            amount: 2000,
            mode: 'sandbox',
            portalUrl: null,
            qrSvg: '<svg data-qr="orphan-receipt"></svg>',
        },
    ],
};
const noLinks = render(withoutDestinations);
assert.match(noLinks, /NO-LINK-GUEST/);
assert.match(noLinks, /NO-LINK-RECEIPT/);
assert.match(noLinks, /Departed before stay check-out/);
assert.match(noLinks, /USD 20\.00/);
assert.match(noLinks, /TEST, NOT FISCAL/);
assert.doesNotMatch(
    noLinks,
    /Scan to verify|data-qr=|VFMS QR code|Private Guest/,
);
assert.doesNotMatch(
    render({ ...withoutDestinations, guestReports: [] }),
    /Scan to verify|data-qr=|VFMS QR code/,
);

const linkOnly = render({
    ...withoutDestinations,
    guestReports: [
        {
            ...withoutDestinations.guestReports[0],
            portalUrl:
                'https://viewer.example/checkout-report/guest/NO-LINK-GUEST',
            qrSvg: null,
        },
    ],
});
assert.match(
    linkOnly,
    /href="https:\/\/viewer\.example\/checkout-report\/guest\/NO-LINK-GUEST"[^>]*>Scan to verify with ZRA<\/a>/,
);
assert.doesNotMatch(linkOnly, /data-qr=|VFMS QR code/);

// Until VFMS accepts every filing the report shows no figures.
const waiting = render({ ...base, status: 'waiting', report: null });
assert.match(waiting, /Waiting for VFMS/);
assert.doesNotMatch(waiting, /SBX-1A2B|USD 239\.00/);
assert.match(
    render({ ...base, status: 'waiting', report: null, needsAttention: true }),
    /Needs attention: ZRA returned no verifiable check-out report/,
);

// protects=#220 the letterhead is the property's, never ZRA's; fails_when=the ZRA logo returns, the logo is missing or fiscal identity is replaced.
assert.doesNotMatch(sandbox, /zra-logo|Zanzibar Revenue Authority|<img/);
assert.match(sandbox, /<p[^>]*>Sea Inn<\/p>/);
const branded = render({
    ...recordedProps,
    branding: {
        logo_url: 'data:image/png;base64,TE9HTw==',
        accent_colour: '#1f6f5c',
    },
});
assert.match(branded, /<img[^>]*src="data:image\/png;base64,TE9HTw=="/);
assert.match(branded, /<img[^>]*alt="Sea Inn"/);
assert.doesNotMatch(branded, /zra-logo/);
assert.match(branded, /#1f6f5c/);
assert.match(branded, /ZRA Test Hotel/);
assert.match(branded, /TIN TEST-TIN/);
assert.match(branded, /TEST, NOT FISCAL/);

console.log('Fiscal check-out report: PASS');
