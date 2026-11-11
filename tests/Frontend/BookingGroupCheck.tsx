import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
import GroupStatement from '@/pages/reservations/group-statement';
import {
    StatementFolios,
    type StatementProps,
} from '@/pages/reservations/statement';
import {
    GroupMemberActions,
    type GroupMember,
} from '@/features/reservations/group-member-actions';

const member: GroupMember = {
    reservation_number: 'R-1043',
    status: 'confirmed',
    arrival: '2026-12-10',
    departure: '2026-12-12',
    can: { checkIn: true, checkOut: false, overrideBalance: false },
    checkoutBalances: [],
    paymentFolios: [],
    channelCollectedAmount: null,
};

function actions(row: GroupMember) {
    return renderToStaticMarkup(
        <App
            initialPage={{
                component: 'group',
                props: {
                    blockCheckoutIfUnpaid: true,
                    tenant: { property: { id: 'property-1' } },
                },
                url: '/reservations/R-1042',
                version: null,
                clearHistory: false,
                encryptHistory: false,
            }}
            initialComponent={() => (
                <GroupMemberActions
                    member={row}
                    acceptedPaymentMethods={['bank_transfer']}
                />
            )}
        />,
    );
}

const arrival = actions(member);
assert.match(arrival, /action="[^"]*R-1043\/check-in"/);
assert.match(arrival, />Check in<\/button>/);
assert.doesNotMatch(arrival, /Check out/);
const departure = actions({
    ...member,
    can: { ...member.can, checkIn: false, checkOut: true },
    checkoutBalances: [{ id: 'folio-1043', currency: 'USD', balance: 500 }],
});
assert.match(departure, /Check out/);
assert.match(departure, /disabled=""/);
assert.match(departure, /All outstanding balances must be paid/);
assert.doesNotMatch(departure, /Check in/);
assert.equal(
    actions({
        ...member,
        can: { ...member.can, checkIn: false },
    }).includes('<button'),
    false,
);
assert.match(
    actions({
        ...member,
        paymentFolios: [
            {
                id: 'folio-1043',
                currency: 'USD',
                total: {
                    currency: 'USD',
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
                    estimate: { nights: 2, amount: 22000 },
                    due: 22000,
                    status: 'unpaid',
                },
            },
        ],
    }),
    />Pay<\/button>/,
);
assert.doesNotMatch(arrival, />Pay<\/button>/);

const statementSummary = {
    room: 8696,
    extras: 0,
    vat: 1304,
    levy: 500,
    other_taxes: 0,
    unclassified_taxes: 0,
    charges: 10500,
    paid: 10000,
    refunded: 0,
    written_off: 0,
    net_payments: 10000,
    balance: 500,
    levy_unavailable: false,
};
const statementRoom: StatementProps = {
    reservation: {
        reservation_number: 'R-245',
        guest: 'Amina',
        room: '101',
        room_type: 'Deluxe Double',
        arrival: '2026-12-10',
        departure: '2026-12-12',
        nights: 2,
        adults: 1,
        children: 0,
    },
    property: {
        name: 'Sea Inn',
        address_line: 'Stone Town',
        city: 'Zanzibar',
        phone: '+255',
        email: 'desk@example.test',
    },
    taxSource: 'vfms',
    totals: [
        {
            currency: 'USD',
            ...statementSummary,
            estimate: null,
            due: 500,
            status: 'partially_paid',
        },
    ],
    folios: [
        {
            number: 1,
            currency: 'USD',
            summary: statementSummary,
            postings: [
                {
                    id: 'charge',
                    type: 'charge',
                    category: 'room',
                    description: null,
                    quantity: 1,
                    unit_price: 10000,
                    amount: 10500,
                    method: null,
                    business_date: '2026-12-10',
                    tax_lines: [
                        {
                            name: 'VAT',
                            inclusive: true,
                            amount: 1304,
                            unavailable: false,
                        },
                    ],
                },
                {
                    id: 'payment',
                    type: 'payment',
                    category: 'payment',
                    description: null,
                    quantity: 1,
                    unit_price: -10000,
                    amount: -10000,
                    method: 'bank_transfer',
                    business_date: '2026-12-10',
                    tax_lines: [],
                },
            ],
        },
    ],
};
const groupStatement = renderToStaticMarkup(
    <App
        initialPage={{
            component: 'reservations/group-statement',
            props: {},
            url: '/reservations/R-245/group-statement',
            version: null,
            clearHistory: false,
            encryptHistory: false,
        }}
        initialComponent={() => (
            <GroupStatement
                reservation={statementRoom.reservation}
                property={statementRoom.property}
                payingInstitution={{
                    name: 'Island Research',
                    zrbNumber: 'ZRB-245',
                }}
                group={{
                    arrival: '2026-12-10',
                    departure: '2026-12-12',
                    rooms: 2,
                    guests: 2,
                }}
                rooms={[
                    { ...statementRoom, reports: ['ZRA-245', 'ZRA-246'] },
                    {
                        ...statementRoom,
                        reservation: {
                            ...statementRoom.reservation,
                            reservation_number: 'R-246',
                            room: '102',
                        },
                        reports: [],
                    },
                ]}
                totals={[
                    {
                        currency: 'USD',
                        ...statementSummary,
                        estimate: { nights: 2, amount: 21000 },
                    },
                    { currency: 'TZS', ...statementSummary, estimate: null },
                ]}
            />
        )}
    />,
);
assert.match(groupStatement, /GROUP STATEMENT/);
assert.match(groupStatement, /Non-fiscal settlement statement/);
assert.match(groupStatement, /data-paper="a4"/);
assert.match(groupStatement, /receipt-print/);
assert.match(groupStatement, /Island Research/);
assert.match(groupStatement, /ZRB-245/);
assert.match(groupStatement, /R-245/);
assert.match(groupStatement, /R-246/);
assert.match(groupStatement, /Bank transfer/);
assert.match(groupStatement, /VAT, of which/);
assert.match(groupStatement, /ZRA fiscal reports/);
assert.match(groupStatement, /ZRA-245/);
assert.match(groupStatement, /ZRA-246/);
assert.match(groupStatement, /Not yet filed/);
assert.match(groupStatement, /USD 5\.00/);
assert.match(groupStatement, /TZS 500/);
assert.match(groupStatement, /Print group statement/);
assert.match(groupStatement, /Not yet charged: 2 nights/);
assert.match(groupStatement, /<h3[^>]*>Folio 1 \(USD\)<\/h3>/);
assert.match(
    renderToStaticMarkup(<StatementFolios {...statementRoom} />),
    /<h2[^>]*>Folio 1 \(USD\)<\/h2>/,
);
console.log('Group statement print content: PASS');
console.log('Booking group member actions: PASS');
