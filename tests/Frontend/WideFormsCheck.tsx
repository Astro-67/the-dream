import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import Maintenance from '@/pages/maintenance/index';
import Create from '@/pages/reservations/create';
import Show from '@/pages/reservations/show';
import type { ComponentProps } from 'react';

function render(Page: ComponentType) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'page',
                url: '/',
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: {
                    errors: {},
                    auth: {
                        user: { id: 'u' },
                        platformRoles: [],
                        permissions: [],
                    },
                    tenant: { property: { id: 'p-1' } },
                    countries: [],
                },
            }}
            initialComponent={Page}
            resolveComponent={async () => Page}
        />,
    );
}

function maintenance(room: string | null) {
    return render(() => (
        <Maintenance
            view="open"
            tickets={{ data: [], links: [], meta: {} } as never}
            rooms={[{ id: 'r-1', number: '101' }]}
            room={room}
            categories={[{ value: 'repair', label: 'Repair' }]}
            priorities={[{ value: 'medium', label: 'Medium' }]}
        />
    ));
}
// Dialog content portals out of server markup, so the trigger's state stands in for it.
assert.match(maintenance(null), /aria-expanded="false"[^>]*>Report a problem</);
assert.match(
    maintenance('r-1'),
    /aria-expanded="true"[^>]*>Report a problem</,
    'A room link opens the report form',
);
console.log('Maintenance report form opens on request: PASS');

const booking = render(() => (
    <Create
        stay={{
            arrival: '2026-12-10',
            departure: '2026-12-13',
            adults: 2,
            children: 0,
        }}
        roomType={null}
        ratePlan={{ id: 'plan', name: 'Standard', currency: 'USD' }}
        ratePlans={[
            {
                id: 'plan',
                name: 'Standard',
                currency: 'USD',
                room_type_ids: [],
            },
        ]}
        rooms={[]}
        restrictionWarnings={[]}
        nightlyRate={10000}
        businessDate="2026-12-01"
    />
));
assert.match(booking, /<dt[^>]*>Nights<\/dt><dd[^>]*>3<\/dd>/);
assert.match(booking, /USD 100\.00/);
assert.match(booking, /<aside[^>]*>[\s\S]*Confirm reservation[\s\S]*<\/aside>/);
console.log('Booking summary keeps confirm beside the stay: PASS');

const emptyPage = {
    data: [],
    total: 0,
    current_page: 1,
    last_page: 1,
    per_page: 50,
    from: null,
    to: null,
    prev_page_url: null,
    next_page_url: null,
    links: [],
};
const stayProps: ComponentProps<typeof Show> = {
    reservation: {
        reservation_number: 'R-1042',
        status: 'confirmed',
        room_id: null,
        room_type_id: 'type',
        rate_plan_id: 'plan',
        arrival: '2026-12-10',
        departure: '2026-12-13',
        adults: 2,
        children: 0,
        source: 'phone',
        meal_plan: 'room-only',
        special_requests: 'Quiet room',
        booked_through: 'direct',
        tour_operator_name: null,
        tour_operator_registration: null,
        tour_operator_pays_for: null,
        channel_reference: null,
        outside_integration_reason: null,
        requires_reconciliation: false,
        payment_collector: 'property',
        channel_collected_amount: null,
        price_lines: [
            { id: 'night', date: '2026-12-10', amount: 10000, currency: 'USD' },
        ],
    },
    roomType: { id: 'type', name: 'Deluxe' },
    assignedRoom: '101',
    ratePlan: { id: 'plan', name: 'Breakfast' },
    booker: { id: 'booker', name: 'Travel Booker' },
    leadGuest: { id: 'guest', name: 'Amina' },
    rooms: [],
    canAssignRoom: true,
    canAmend: true,
    amendmentOptions: { roomTypes: [], ratePlans: [], rooms: [] },
    amendments: emptyPage,
    can: {
        cancel: false,
        void: false,
        moveRoom: true,
        changeStay: false,
        checkIn: true,
        checkOut: false,
        overrideBalance: false,
    },
    moveRooms: [],
    groupMembers: [
        {
            reservation_number: 'R-1043',
            status: 'confirmed',
            arrival: '2026-12-10',
            departure: '2026-12-13',
        },
    ],
    folios: emptyPage,
    totals: [],
    stay: {
        reservation_number: 'R-1042',
        guest: 'Amina',
        room: '101',
        room_type: 'Deluxe',
        nights: 3,
    },
    checkoutBalances: [],
    canPostCharge: false,
    feeProposals: [],
    canRecordPayment: false,
    acceptedPaymentMethods: [],
    extraCategories: [],
    paymentsReportUrl: null,
    vfmsRefusal: null,
    vfmsFilings: [],
};
const reservation = render(() => <Show {...stayProps} />);
// protects=only authorized viewers see the audited re-file form (#173); fails_when=the refusal is hidden, reason is optional or read-only viewers get an action; no_claim=HTTP authorization.
const refusal = {
    message: 'The Room ID 101 Is not Empty',
    refileUrl: '/reservations/R-1042/vfms/message/refile',
};
const rejected = render(() => <Show {...stayProps} vfmsRefusal={refusal} />);
assert.match(rejected, /Not filed with ZRA/);
assert.match(rejected, /Re-file with ZRA/);
assert.match(
    rejected,
    /name="reason"[^>]*required=""|required=""[^>]*name="reason"/,
);
assert.match(rejected, /name="_property"[^>]*value="p-1"/);
assert.doesNotMatch(
    render(() => (
        <Show {...stayProps} vfmsRefusal={{ ...refusal, refileUrl: null }} />
    )),
    /Re-file with ZRA|Reason for re-filing/,
);
// protects=the stay shows each ZRA call with its status and refusal, and actions only when the server grants them (#174); fails_when=a status, number or message is missing, or a null URL still renders an action; no_claim=HTTP authorization.
const filing = {
    attempts: 1,
    number: null,
    message: null,
    refileUrl: null,
    retryUrl: null,
};
const filings = render(() => (
    <Show
        {...stayProps}
        vfmsFilings={[
            {
                ...filing,
                id: 'a',
                call: 'Check-in',
                status: 'filed',
                number: 'AC-174',
            },
            {
                ...filing,
                id: 'b',
                call: 'Extras',
                status: 'refused',
                message: 'The Room ID 1 Is not Empty',
                refileUrl: '/reservations/R-1042/vfms/b/refile',
            },
            {
                ...filing,
                id: 'c',
                call: 'Check-out',
                status: 'waiting',
                attempts: 0,
            },
            { ...filing, id: 'd', call: 'Guest left', status: 'unknown' },
        ]}
    />
));
for (const text of [
    'ZRA filings',
    'Check-in',
    'Filed',
    'AC-174',
    'Extras',
    'Refused',
    'The Room ID 1 Is not Empty',
    'Check-out',
    'Waiting',
    'Unknown',
])
    assert.ok(filings.includes(text), `Missing ZRA filing text: ${text}`);
assert.equal(filings.match(/>Re-file</g)?.length, 1);
assert.doesNotMatch(filings, />Retry</);
const closedFilings = render(() => (
    <Show
        {...stayProps}
        vfmsFiscalExceptionReason="Departed before VFMS filing worked"
        vfmsFilings={[
            { ...filing, id: 'sandbox', call: 'Check-in', status: 'sandbox' },
            { ...filing, id: 'closed', call: 'Check-out', status: 'closed' },
        ]}
    />
));
assert.match(closedFilings, /Sandbox — not fiscal/);
assert.match(closedFilings, /Closed without filing/);
assert.match(
    closedFilings,
    /Fiscal exception: Departed before VFMS filing worked/,
);
assert.doesNotMatch(closedFilings, />Re-file<|>Retry<|>Waiting</);
const closeableFilings = render(() => (
    <Show
        {...stayProps}
        vfmsCloseWithoutFilingUrl="/reservations/R-1042/vfms/close-without-filing"
        vfmsFilings={[
            { ...filing, id: 'rejected', call: 'Check-in', status: 'refused' },
        ]}
    />
));
assert.match(closeableFilings, />Close without filing</);
assert.doesNotMatch(reservation, /ZRA filings/);
for (const label of [
    'More details',
    'Room assignment',
    'Move room',
    'Amend reservation',
    'Amendment history (0)',
    'Other reservations in this booking group (1)',
    'Fixed nightly prices (1)',
]) {
    assert.ok(
        reservation.includes(label),
        `Missing disclosure trigger: ${label}`,
    );
}
assert.match(reservation, /aria-expanded="false"/);
for (const visible of [
    'Deluxe',
    'Breakfast',
    '101',
    'Amina',
    'Scheduled nights',
    'Payment collector',
])
    assert.ok(reservation.includes(visible));
for (const hidden of [
    'Travel Booker',
    'Quiet room',
    'R-1043',
    'name="room_id"',
    'name="pricing"',
    'No amendments yet.',
])
    assert.ok(
        !reservation.includes(hidden),
        `Content should start hidden: ${hidden}`,
    );
const fee = {
    id: 'fee',
    kind: 'cancellation',
    amount: 1000,
    currency: 'USD',
    status: 'proposed',
    reason: 'Policy fee',
    fiscal_exception_candidate: false,
    can_resolve: true,
} as const;
assert.match(
    render(() => <Show {...stayProps} feeProposals={[fee]} />),
    /Post fee/,
);
assert.doesNotMatch(
    render(() => (
        <Show {...stayProps} feeProposals={[{ ...fee, can_resolve: false }]} />
    )),
    /Policy fee/,
);
console.log(
    'Reservation facts stay visible, secondary forms and history disclose on request: PASS',
);
