import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import { renderToStaticMarkup } from 'react-dom/server';
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
console.log('Booking group member actions: PASS');
