import { ChevronDown } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { FeeProposals } from '@/features/billing/fee-proposals';
import type { FeeProposal } from '@/features/billing/fee-proposals';
import { NativeSelect } from '@/components/ui/select';
import type { Paginated } from '@/types';
import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { checkIn, roomAssignment } from '@/routes/reservations';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TerminationActions } from '@/features/reservations/termination-actions';
import { CheckOutDialog } from '@/features/front-desk/check-out-dialog';
import type { CheckoutBalance } from '@/features/front-desk/check-out-dialog';
import { StayLengthActions } from '@/features/front-desk/stay-length-actions';
import { formatDate } from '@/features/platform/format';
import {
    AmendmentForm,
    AmendmentHistory,
    type Amendment,
    type AmendmentOptions,
} from '@/features/reservations/amendment-form';
import { show } from '@/routes/reservations';
import { formatMoney } from '@/lib/utils';
import {
    RoomMoveForm,
    type MoveRoom,
} from '@/features/front-desk/room-move-form';
import {
    FolioPanel,
    type Folio,
    type FolioStay,
    type FolioTotal,
} from '@/features/billing/folio-panel';
import { Spinner } from '@/components/ui/spinner';

type Named = { id: string; name: string };
type Reservation = {
    reservation_number: string;
    status: string;
    room_id: string | null;
    room_type_id: string;
    rate_plan_id: string;
    arrival: string;
    departure: string;
    adults: number;
    children: number;
    source: string;
    meal_plan: string;
    special_requests: string | null;
    booked_through: string;
    tour_operator_name: string | null;
    tour_operator_registration: string | null;
    tour_operator_pays_for: string | null;
    channel_reference: string | null;
    outside_integration_reason: string | null;
    requires_reconciliation: boolean;
    payment_collector: string;
    channel_collected_amount: number | null;
    price_lines: {
        id: string;
        date: string;
        amount: number;
        currency: string;
    }[];
};

export default function Show({
    reservation,
    roomType,
    assignedRoom,
    ratePlan,
    booker,
    leadGuest,
    rooms,
    canAssignRoom,
    canAmend,
    amendmentOptions,
    amendments,
    can,
    moveRooms,
    groupMembers,
    folios,
    totals,
    stay,
    checkoutBalances,
    canPostCharge,
    feeProposals,
    canRecordPayment,
    acceptedPaymentMethods,
    extraCategories,
    paymentsReportUrl,
}: {
    reservation: Reservation;
    roomType: Named;
    assignedRoom: string | null;
    ratePlan: Named;
    booker: Named | null;
    leadGuest: Named | null;
    rooms: { id: string; number: string; isReady: boolean }[];
    canAssignRoom: boolean;
    canAmend: boolean;
    amendmentOptions: AmendmentOptions | null;
    amendments: Paginated<Amendment>;
    can: {
        cancel: boolean;
        void: boolean;
        moveRoom: boolean;
        changeStay: boolean;
        checkIn: boolean;
        checkOut: boolean;
        overrideBalance: boolean;
    };
    moveRooms: MoveRoom[];
    groupMembers: Pick<
        Reservation,
        'reservation_number' | 'status' | 'arrival' | 'departure'
    >[];
    folios: Paginated<Folio>;
    totals: FolioTotal[];
    stay: FolioStay;
    checkoutBalances: CheckoutBalance[];
    canPostCharge: boolean;
    feeProposals: FeeProposal[];
    canRecordPayment: boolean;
    acceptedPaymentMethods: string[];
    extraCategories: string[];
    paymentsReportUrl: string | null;
}) {
    return (
        <>
            <Head title={`Reservation ${reservation.reservation_number}`} />
            <div className="flex flex-col gap-6 p-4 md:gap-8 md:p-6">
                <header className="flex flex-wrap items-center gap-3 [&_button]:min-h-11">
                    <h1 className="text-2xl font-semibold">
                        Reservation{' '}
                        <Identifier
                            value={reservation.reservation_number}
                            copyable
                        />
                    </h1>
                    <StatusChip
                        tone={
                            reservation.status === 'confirmed'
                                ? 'success'
                                : 'unavailable'
                        }
                    >
                        {reservation.status}
                    </StatusChip>
                    {reservation.requires_reconciliation && (
                        <StatusChip tone="warning">
                            Requires reconciliation
                        </StatusChip>
                    )}
                    {can.checkIn && (
                        <Form
                            {...checkIn.form(reservation.reservation_number)}
                            disableWhileProcessing
                            errorBag={`check-in-${reservation.reservation_number}`}
                            options={{ preserveScroll: true }}
                            className="flex flex-col items-start gap-1"
                        >
                            {({ errors }) => (
                                <>
                                    <PropertyIntent />
                                    <Button type="submit">Check in</Button>
                                    {Object.values(errors).map((error) => (
                                        <InputError
                                            key={error}
                                            message={error}
                                        />
                                    ))}
                                </>
                            )}
                        </Form>
                    )}
                    {reservation.status === 'confirmed' && (
                        <TerminationActions
                            number={reservation.reservation_number}
                            can={can}
                        />
                    )}
                    {reservation.status === 'checked_in' && can.checkOut && (
                        <CheckOutDialog
                            number={reservation.reservation_number}
                            balances={checkoutBalances}
                            canOverride={can.overrideBalance}
                        />
                    )}
                    {reservation.status === 'checked_in' && can.changeStay && (
                        <StayLengthActions
                            number={reservation.reservation_number}
                            departure={reservation.departure}
                        />
                    )}
                </header>
                <Card className="gap-4 py-4 md:py-6">
                    <CardContent className="space-y-4 px-4 md:px-6">
                        <h2 className="sr-only">Stay details</h2>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-4 lg:grid-cols-3 xl:grid-cols-5">
                            {[
                                ['Room', assignedRoom ?? 'Not assigned'],
                                ['Room type', roomType.name],
                                ['Rate plan', ratePlan.name],
                                [
                                    'Arrival',
                                    formatDate(reservation.arrival, 'UTC'),
                                ],
                                [
                                    'Departure',
                                    formatDate(reservation.departure, 'UTC'),
                                ],
                                [
                                    'Scheduled nights',
                                    String(
                                        Math.round(
                                            (Date.parse(reservation.departure) -
                                                Date.parse(
                                                    reservation.arrival,
                                                )) /
                                                86_400_000,
                                        ),
                                    ),
                                ],
                                [
                                    'Guests',
                                    `${reservation.adults} adults, ${reservation.children} children`,
                                ],
                                ['Lead guest', leadGuest?.name ?? 'Not added'],
                                [
                                    'Payment collector',
                                    reservation.payment_collector,
                                ],
                            ].map(([label, value]) => (
                                <div key={label} className="min-w-0">
                                    <dt className="text-sm text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="break-words">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <Collapsible>
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="group -mx-3 min-h-11"
                                >
                                    More details
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="pt-4">
                                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {[
                                        ...(booker
                                            ? [['Booker', booker.name]]
                                            : []),
                                        [
                                            'Meal plan',
                                            reservation.meal_plan.replaceAll(
                                                '-',
                                                ' ',
                                            ),
                                        ],
                                        [
                                            'Booked through',
                                            reservation.tour_operator_name ??
                                                'Guest directly',
                                        ],
                                        ...(reservation.tour_operator_registration
                                            ? [
                                                  [
                                                      'Tour operator ZRA registration',
                                                      reservation.tour_operator_registration,
                                                  ],
                                                  [
                                                      'Tour operator pays for',
                                                      reservation.tour_operator_pays_for?.replaceAll(
                                                          '-',
                                                          ' ',
                                                      ) ?? '',
                                                  ],
                                              ]
                                            : []),
                                        [
                                            'Special requests',
                                            reservation.special_requests ??
                                                'None',
                                        ],
                                        [
                                            'Source',
                                            reservation.source ===
                                            'outside-integration'
                                                ? 'Booking received outside integration'
                                                : reservation.source,
                                        ],
                                        ...(reservation.requires_reconciliation
                                            ? [
                                                  [
                                                      'Channel reference',
                                                      reservation.channel_reference ??
                                                          '',
                                                  ],
                                                  [
                                                      'Reason received outside integration',
                                                      reservation.outside_integration_reason ??
                                                          '',
                                                  ],
                                              ]
                                            : []),
                                        ...(reservation.channel_collected_amount !==
                                        null
                                            ? [
                                                  [
                                                      'Channel-collected amount',
                                                      formatMoney(
                                                          reservation.channel_collected_amount,
                                                          reservation
                                                              .price_lines[0]
                                                              ?.currency ?? '',
                                                      ),
                                                  ],
                                              ]
                                            : []),
                                    ].map(([label, value]) => (
                                        <div key={label}>
                                            <dt className="text-sm text-muted-foreground">
                                                {label}
                                            </dt>
                                            <dd>{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </CollapsibleContent>
                        </Collapsible>
                    </CardContent>
                </Card>
                {((canAssignRoom && reservation.status === 'confirmed') ||
                    can.moveRoom ||
                    (canAmend &&
                        amendmentOptions &&
                        reservation.status === 'confirmed')) && (
                    <div className="flex flex-wrap gap-2">
                        {canAssignRoom &&
                            reservation.status === 'confirmed' && (
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="secondary"
                                            className="min-h-11"
                                        >
                                            Room assignment
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent
                                        className="max-h-[90dvh] overflow-y-auto"
                                        aria-describedby={undefined}
                                    >
                                        <DialogHeader>
                                            <DialogTitle>
                                                Room assignment
                                            </DialogTitle>
                                        </DialogHeader>
                                        <Form
                                            {...roomAssignment.form(
                                                reservation.reservation_number,
                                            )}
                                            className="max-w-[720px] space-y-3"
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <PropertyIntent />
                                                    <Label htmlFor="room_id">
                                                        Room assignment
                                                    </Label>
                                                    <NativeSelect
                                                        id="room_id"
                                                        name="room_id"
                                                        defaultValue={
                                                            reservation.room_id ??
                                                            ''
                                                        }
                                                        aria-describedby="room-error"
                                                    >
                                                        <option value="">
                                                            Unassigned
                                                        </option>
                                                        {rooms.map((room) => (
                                                            <option
                                                                key={room.id}
                                                                value={room.id}
                                                            >
                                                                {room.number} —{' '}
                                                                {room.isReady
                                                                    ? 'Ready'
                                                                    : 'Not ready'}
                                                            </option>
                                                        ))}
                                                    </NativeSelect>
                                                    <div id="room-error">
                                                        <InputError
                                                            message={
                                                                errors.room_id
                                                            }
                                                        />
                                                    </div>
                                                    <Button
                                                        disabled={processing}
                                                    >
                                                        <span className="size-4">
                                                            {processing && (
                                                                <Spinner />
                                                            )}
                                                        </span>
                                                        Save room assignment
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            )}
                        {can.moveRoom && (
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="secondary"
                                        className="min-h-11"
                                    >
                                        Move room
                                    </Button>
                                </DialogTrigger>
                                <DialogContent
                                    className="max-h-[90dvh] overflow-y-auto"
                                    aria-describedby={undefined}
                                >
                                    <DialogHeader>
                                        <DialogTitle>Move room</DialogTitle>
                                    </DialogHeader>
                                    <RoomMoveForm
                                        number={reservation.reservation_number}
                                        rooms={moveRooms}
                                    />
                                </DialogContent>
                            </Dialog>
                        )}
                        {canAmend &&
                            amendmentOptions &&
                            reservation.status === 'confirmed' && (
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="secondary"
                                            className="min-h-11"
                                        >
                                            Amend reservation
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent
                                        className="max-h-[90dvh] overflow-y-auto sm:max-w-[720px]"
                                        aria-describedby={undefined}
                                    >
                                        <DialogHeader>
                                            <DialogTitle>
                                                Amend reservation
                                            </DialogTitle>
                                        </DialogHeader>
                                        <AmendmentForm
                                            reservation={reservation}
                                            options={amendmentOptions}
                                        />
                                    </DialogContent>
                                </Dialog>
                            )}
                    </div>
                )}
                <FolioPanel
                    stay={stay}
                    folios={folios}
                    totals={totals}
                    canPostCharge={canPostCharge}
                    canRecordPayment={canRecordPayment}
                    acceptedPaymentMethods={acceptedPaymentMethods}
                    extraCategories={extraCategories}
                    paymentsReportUrl={paymentsReportUrl}
                    channelCollectedAmount={
                        reservation.payment_collector === 'channel'
                            ? reservation.channel_collected_amount
                            : null
                    }
                />
                {feeProposals.length > 0 && (
                    <Collapsible
                        defaultOpen={feeProposals.some(
                            (fee) => fee.can_resolve,
                        )}
                    >
                        <h2>
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="group -mx-3 h-auto min-h-11 max-w-full justify-start text-left text-lg font-semibold whitespace-normal"
                                >
                                    Fee proposals ({feeProposals.length})
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                </Button>
                            </CollapsibleTrigger>
                        </h2>
                        <CollapsibleContent className="pt-4">
                            <FeeProposals fees={feeProposals} />
                        </CollapsibleContent>
                    </Collapsible>
                )}
                <Collapsible>
                    <h2>
                        <CollapsibleTrigger asChild>
                            <Button
                                variant="ghost"
                                className="group -mx-3 h-auto min-h-11 max-w-full justify-start text-left text-lg font-semibold whitespace-normal"
                            >
                                Amendment history ({amendments.total})
                                <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                            </Button>
                        </CollapsibleTrigger>
                    </h2>
                    <CollapsibleContent className="pt-4">
                        <AmendmentHistory amendments={amendments} />
                    </CollapsibleContent>
                </Collapsible>
                <Link
                    className="text-primary underline underline-offset-4"
                    href={`/reservations/${encodeURIComponent(reservation.reservation_number)}/guests`}
                >
                    View and attach staying guests
                </Link>
                {groupMembers.length > 0 && (
                    <Collapsible>
                        <h2>
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="group -mx-3 h-auto min-h-11 max-w-full justify-start text-left text-lg font-semibold whitespace-normal"
                                >
                                    Other reservations in this booking group (
                                    {groupMembers.length})
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                </Button>
                            </CollapsibleTrigger>
                        </h2>
                        <CollapsibleContent className="pt-4">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            Reservation number
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Arrival</TableHead>
                                        <TableHead>Departure</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {groupMembers.map((member) => (
                                        <TableRow
                                            key={member.reservation_number}
                                        >
                                            <TableCell>
                                                <Link
                                                    className="text-primary underline underline-offset-4"
                                                    href={show(
                                                        member.reservation_number,
                                                    )}
                                                >
                                                    <Identifier
                                                        value={
                                                            member.reservation_number
                                                        }
                                                    />
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <StatusChip
                                                    tone={
                                                        member.status ===
                                                        'confirmed'
                                                            ? 'success'
                                                            : 'unavailable'
                                                    }
                                                >
                                                    {member.status}
                                                </StatusChip>
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(
                                                    member.arrival,
                                                    'UTC',
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(
                                                    member.departure,
                                                    'UTC',
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CollapsibleContent>
                    </Collapsible>
                )}
                <Collapsible>
                    <h2>
                        <CollapsibleTrigger asChild>
                            <Button
                                variant="ghost"
                                className="group -mx-3 h-auto min-h-11 max-w-full justify-start text-left text-lg font-semibold whitespace-normal"
                            >
                                Fixed nightly prices (
                                {reservation.price_lines.length})
                                <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                            </Button>
                        </CollapsibleTrigger>
                    </h2>
                    <CollapsibleContent className="pt-4">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Night</TableHead>
                                    <TableHead numeric>Amount</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reservation.price_lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            {formatDate(line.date, 'UTC')}
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                line.amount,
                                                line.currency,
                                            )}{' '}
                                            {line.currency}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </>
    );
}
