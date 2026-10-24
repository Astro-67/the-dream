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
assert.match(sandbox, /SANDBOX, NOT FISCAL/);
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
assert.doesNotMatch(live, /SANDBOX/);

// Until VFMS accepts every filing the report shows no figures.
const waiting = render({ ...base, status: 'waiting', report: null });
assert.match(waiting, /Waiting for VFMS/);
assert.doesNotMatch(waiting, /SBX-1A2B|USD 239\.00/);

console.log('Fiscal check-out report: PASS');
