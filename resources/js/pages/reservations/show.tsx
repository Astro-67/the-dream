import {
    BedDouble,
    CalendarCheck,
    CalendarDays,
    ChevronDown,
    CreditCard,
    DoorOpen,
    FileText,
    Globe,
    Moon,
    MoveRight,
    PencilLine,
    ReceiptText,
    Settings2,
    ShieldCheck,
    Tags,
    UserRound,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
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
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { roomAssignment } from '@/routes/reservations';
import { CheckInForm } from '@/features/front-desk/check-in-form';
import {
    GroupMemberActions,
    type GroupMember,
} from '@/features/reservations/group-member-actions';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip, type StatusTone } from '@/components/ui/status-chip';
import {
    ZraDocumentsSummary,
    type ZraDocumentsOverview,
} from '@/features/fiscal-receipting/zra-documents';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TerminationActions } from '@/features/reservations/termination-actions';
import { PayingInstitutionDialog } from '@/features/reservations/paying-institution-dialog';
import { CheckOutDialog } from '@/features/front-desk/check-out-dialog';
import type { CheckoutBalance } from '@/features/front-desk/check-out-dialog';
import { StayLengthActions } from '@/features/front-desk/stay-length-actions';
import {
    GeneralReceiptDialog,
    type GeneralReceiptPreview,
} from '@/features/fiscal-receipting/general-receipt-dialog';
import { formatDate } from '@/features/platform/format';
import {
    AmendmentForm,
    AmendmentHistory,
    type Amendment,
    type AmendmentOptions,
} from '@/features/reservations/amendment-form';
import { groupStatement, index, show } from '@/routes/reservations';
import { show as showGuest } from '@/routes/guests';
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
import type { TaxSource } from '@/features/billing/folio-summary';
import { Spinner } from '@/components/ui/spinner';
import {
    VfmsRefusalBanner,
    type VfmsRefusal,
} from '@/features/fiscal-receipting/vfms-refusal-banner';
import {
    VfmsFilingsPanel,
    type VfmsFiling,
} from '@/features/fiscal-receipting/vfms-filings-panel';

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
    paying_institution_type: 'government' | 'business' | null;
    paying_institution_name: string | null;
    paying_institution_zrb_number: string | null;
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

const reservationStatusTones: Record<string, StatusTone> = {
    confirmed: 'info',
    checked_in: 'occupied',
    checked_out: 'success',
    cancelled: 'unavailable',
    no_show: 'warning',
    void: 'unavailable',
};

const reservationStatusLabels: Record<string, string> = {
    confirmed: 'Confirmed',
    checked_in: 'Checked in',
    checked_out: 'Checked out',
    cancelled: 'Cancelled',
    no_show: 'No show',
    void: 'Void',
};

const stayDetailIcons: Record<string, LucideIcon> = {
    Room: BedDouble,
    'Room type': DoorOpen,
    'Rate plan': Tags,
    Arrival: CalendarCheck,
    Departure: CalendarDays,
    'Scheduled nights': Moon,
    Guests: UsersRound,
    'Lead guest': UserRound,
    'Payment collector': CreditCard,
    'Paying institution ZRB number': ShieldCheck,
    'Meal plan': FileText,
    'Booked through': Globe,
    Source: Globe,
};

export default function Show({
    reservation,
    roomType,
    assignedRoom,
    ratePlan,
    booker,
    leadGuest,
    canViewLeadGuest,
    missingGuests,
    registrationBlocked,
    registrationGuests,
    canAttachGuest,
    canViewIdentity,
    rooms,
    canAssignRoom,
    canAmend,
    showsPayingInstitution,
    canEditPayingInstitution,
    amendmentOptions,
    amendments,
    can,
    moveRooms,
    groupMembers,
    folios,
    totals,
    taxSource,
    stay,
    checkoutBalances,
    canPostCharge,
    feeProposals,
    canRecordPayment,
    acceptedPaymentMethods,
    extraCategories,
    generalReceipt,
    zraDocuments,
    paymentsReportUrl,
    vfmsRefusal,
    vfmsFilings,
    vfmsCloseWithoutFilingUrl,
    vfmsFiscalExceptionReason,
}: {
    reservation: Reservation;
    roomType: Named;
    assignedRoom: string | null;
    ratePlan: Named;
    booker: Named | null;
    leadGuest: Named | null;
    canViewLeadGuest: boolean;
    missingGuests: number;
    registrationBlocked: boolean;
    registrationGuests: {
        id: string;
        name: string;
        canView: boolean;
        canEdit: boolean;
    }[];
    canAttachGuest: boolean;
    canViewIdentity: boolean;
    rooms: { id: string; number: string; isReady: boolean }[];
    canAssignRoom: boolean;
    canAmend: boolean;
    showsPayingInstitution: boolean;
    canEditPayingInstitution: boolean;
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
    groupMembers: GroupMember[];
    folios: Paginated<Folio>;
    totals: FolioTotal[];
    taxSource: TaxSource;
    stay: FolioStay;
    checkoutBalances: CheckoutBalance[];
    canPostCharge: boolean;
    feeProposals: FeeProposal[];
    canRecordPayment: boolean;
    acceptedPaymentMethods: string[];
    extraCategories: string[];
    generalReceipt: GeneralReceiptPreview | null;
    zraDocuments: ZraDocumentsOverview | null;
    paymentsReportUrl: string | null;
    vfmsRefusal: VfmsRefusal | null;
    vfmsFilings: VfmsFiling[];
    vfmsCloseWithoutFilingUrl?: string | null;
    vfmsFiscalExceptionReason?: string | null;
}) {
    const [assignmentOpen, setAssignmentOpen] = useState(false);
    const [moveOpen, setMoveOpen] = useState(false);
    const [amendmentOpen, setAmendmentOpen] = useState(false);
    return (
        <>
            <Head
                title={
                    leadGuest?.name ??
                    `Reservation ${reservation.reservation_number}`
                }
            />
            <div className="flex min-w-0 flex-col gap-6 md:gap-8">
                <VfmsRefusalBanner refusal={vfmsRefusal} />
                <header className="overflow-hidden border border-border bg-card shadow-panel">
                    <div className="flex flex-wrap items-center gap-4 border-l-4 border-l-primary p-4 md:p-6">
                        <span className="grid size-12 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <UserRound aria-hidden="true" className="size-6" />
                        </span>
                        <div className="min-w-0 flex-1 space-y-1">
                            <p className="text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                Guest stay / Reservation details
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="min-w-0 text-3xl font-semibold tracking-tight wrap-anywhere md:text-4xl">
                                    {canViewLeadGuest && leadGuest ? (
                                        <Link
                                            href={showGuest(leadGuest.id)}
                                            className="text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        >
                                            {leadGuest.name}
                                        </Link>
                                    ) : (
                                        (leadGuest?.name ?? 'Guest not added')
                                    )}
                                </h1>
                                <StatusChip
                                    tone={
                                        reservationStatusTones[
                                            reservation.status
                                        ] ?? 'unavailable'
                                    }
                                >
                                    {reservationStatusLabels[
                                        reservation.status
                                    ] ??
                                        reservation.status.replaceAll('_', ' ')}
                                </StatusChip>
                                {reservation.requires_reconciliation && (
                                    <StatusChip tone="warning">
                                        Requires reconciliation
                                    </StatusChip>
                                )}
                            </div>
                            <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                <span className="inline-flex items-center gap-1.5">
                                    <span className="text-xs font-semibold tracking-[0.06em] uppercase">
                                        Reservation
                                    </span>
                                    <Identifier
                                        value={reservation.reservation_number}
                                        copyable
                                        className="text-sm"
                                    />
                                </span>
                                <span aria-hidden="true">·</span>
                                <span className="inline-flex items-center gap-1.5">
                                    <CalendarDays
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {formatDate(reservation.arrival, 'UTC')} –{' '}
                                    {formatDate(reservation.departure, 'UTC')}
                                </span>
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 border-t border-border bg-muted/20 px-4 py-3 md:px-6">
                        <span className="mr-1 text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                            Reservation actions
                        </span>
                        <Button variant="outline" asChild>
                            <Link
                                href={`/reservations/${encodeURIComponent(reservation.reservation_number)}/guests`}
                            >
                                <UsersRound aria-hidden="true" />
                                View and attach staying guests
                            </Link>
                        </Button>
                        {can.checkIn && (
                            <CheckInForm
                                number={reservation.reservation_number}
                                guestProfileId={leadGuest?.id}
                                missingGuests={missingGuests}
                                canAddGuests={canAttachGuest && canViewIdentity}
                                registrationBlocked={registrationBlocked}
                                incompleteGuests={registrationGuests}
                            />
                        )}
                        {reservation.status === 'confirmed' && (
                            <TerminationActions
                                number={reservation.reservation_number}
                                can={can}
                            />
                        )}
                        {reservation.status === 'checked_in' &&
                            can.checkOut && (
                                <CheckOutDialog
                                    number={reservation.reservation_number}
                                    balances={checkoutBalances}
                                    canOverride={can.overrideBalance}
                                />
                            )}
                        {reservation.status === 'checked_in' &&
                            can.changeStay && (
                                <StayLengthActions
                                    number={reservation.reservation_number}
                                    departure={reservation.departure}
                                />
                            )}
                    </div>
                </header>
                <section
                    aria-labelledby="stay-details-heading"
                    className="overflow-hidden border border-border bg-card shadow-panel"
                >
                    <div className="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-4 md:px-5">
                        <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                            <BedDouble aria-hidden="true" className="size-5" />
                        </span>
                        <div>
                            <h2
                                id="stay-details-heading"
                                className="text-base font-semibold"
                            >
                                Stay overview
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Room, guest, and rate details
                            </p>
                        </div>
                    </div>
                    <div className="space-y-4 p-4 md:p-5">
                        <dl className="grid min-w-0 gap-px border border-border bg-border sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
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
                                ...(showsPayingInstitution &&
                                reservation.paying_institution_type
                                    ? [
                                          [
                                              reservation.paying_institution_type ===
                                              'government'
                                                  ? 'Paid by government institution (withholding)'
                                                  : 'Paid by registered business (B2B)',
                                              reservation.paying_institution_name ??
                                                  '',
                                          ],
                                          [
                                              'Paying institution ZRB number',
                                              reservation.paying_institution_zrb_number ??
                                                  '',
                                          ],
                                      ]
                                    : []),
                            ].map(([label, value]) => {
                                const Icon = stayDetailIcons[label] ?? FileText;

                                return (
                                    <div
                                        key={label}
                                        className="flex min-w-0 items-start gap-3 bg-card p-3 md:p-4"
                                    >
                                        <span className="grid size-8 shrink-0 place-items-center border border-primary/10 bg-primary-tint text-primary">
                                            <Icon
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                        </span>
                                        <div className="min-w-0">
                                            <dt className="text-xs font-semibold tracking-[0.06em] text-muted-foreground uppercase">
                                                {label}
                                            </dt>
                                            <dd className="mt-1 text-sm leading-relaxed font-medium wrap-anywhere">
                                                {value}
                                            </dd>
                                        </div>
                                    </div>
                                );
                            })}
                        </dl>
                        <Collapsible className="border-t border-border pt-3">
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="group -ml-3 min-h-11"
                                >
                                    <FileText aria-hidden="true" />
                                    More details
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="mt-2 border border-border bg-muted/20 p-4">
                                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
                                        <div
                                            key={label}
                                            className="border border-border bg-card p-3"
                                        >
                                            <dt className="text-xs font-semibold tracking-[0.06em] text-muted-foreground uppercase">
                                                {label}
                                            </dt>
                                            <dd className="mt-1 text-sm leading-relaxed wrap-anywhere">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </CollapsibleContent>
                        </Collapsible>
                    </div>
                </section>{' '}
                {((canAssignRoom && reservation.status === 'confirmed') ||
                    can.moveRoom ||
                    canEditPayingInstitution ||
                    (canAmend &&
                        amendmentOptions &&
                        reservation.status === 'confirmed')) && (
                    <section
                        aria-labelledby="reservation-options-title"
                        className="overflow-hidden border border-border bg-card shadow-panel"
                    >
                        <div className="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-4 md:px-5">
                            <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                <Settings2
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            </span>
                            <div>
                                <h2
                                    id="reservation-options-title"
                                    className="text-base font-semibold"
                                >
                                    Reservation options
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Room and booking management
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2 p-4 md:p-5">
                            {canAssignRoom &&
                                reservation.status === 'confirmed' && (
                                    <Dialog
                                        open={assignmentOpen}
                                        onOpenChange={setAssignmentOpen}
                                    >
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="secondary"
                                                className="min-h-11"
                                            >
                                                <DoorOpen aria-hidden="true" />
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
                                                onSuccess={() =>
                                                    setAssignmentOpen(false)
                                                }
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
                                                            {rooms.map(
                                                                (room) => (
                                                                    <option
                                                                        key={
                                                                            room.id
                                                                        }
                                                                        value={
                                                                            room.id
                                                                        }
                                                                    >
                                                                        {
                                                                            room.number
                                                                        }{' '}
                                                                        —{' '}
                                                                        {room.isReady
                                                                            ? 'Ready'
                                                                            : 'Not ready'}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </NativeSelect>
                                                        <div id="room-error">
                                                            <InputError
                                                                message={
                                                                    errors.room_id
                                                                }
                                                            />
                                                        </div>
                                                        <Button
                                                            disabled={
                                                                processing
                                                            }
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
                                <Dialog
                                    open={moveOpen}
                                    onOpenChange={setMoveOpen}
                                >
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="secondary"
                                            className="min-h-11"
                                        >
                                            <MoveRight aria-hidden="true" />
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
                                            number={
                                                reservation.reservation_number
                                            }
                                            rooms={moveRooms}
                                            onSuccess={() => setMoveOpen(false)}
                                        />
                                    </DialogContent>
                                </Dialog>
                            )}
                            {canAmend &&
                                amendmentOptions &&
                                reservation.status === 'confirmed' && (
                                    <Dialog
                                        open={amendmentOpen}
                                        onOpenChange={setAmendmentOpen}
                                    >
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="secondary"
                                                className="min-h-11"
                                            >
                                                <PencilLine aria-hidden="true" />
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
                                                onSuccess={() =>
                                                    setAmendmentOpen(false)
                                                }
                                            />
                                        </DialogContent>
                                    </Dialog>
                                )}
                            {canEditPayingInstitution && (
                                <PayingInstitutionDialog
                                    reservation={reservation}
                                />
                            )}
                        </div>
                    </section>
                )}
                <FolioPanel
                    groupStatementLink={
                        !zraDocuments && (
                            <Link
                                href={groupStatement(
                                    reservation.reservation_number,
                                )}
                                className="text-primary underline underline-offset-4"
                            >
                                Group statement
                            </Link>
                        )
                    }
                    stay={stay}
                    folios={folios}
                    totals={totals}
                    taxSource={taxSource}
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
                        className="border border-border bg-card p-4 shadow-panel md:p-5"
                    >
                        <h2>
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="group h-auto min-h-11 w-full max-w-full justify-between text-left text-lg font-semibold whitespace-normal"
                                >
                                    <span className="inline-flex items-center gap-2">
                                        <ReceiptText
                                            aria-hidden="true"
                                            className="size-5 text-primary"
                                        />
                                        Fee proposals ({feeProposals.length})
                                    </span>
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                </Button>
                            </CollapsibleTrigger>
                        </h2>
                        <CollapsibleContent className="border-t border-border pt-4">
                            <FeeProposals fees={feeProposals} />
                        </CollapsibleContent>
                    </Collapsible>
                )}
                {zraDocuments && (
                    <section
                        className="overflow-hidden border border-border bg-card shadow-panel"
                        aria-label="ZRA documents"
                    >
                        <div className="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-4 md:px-5">
                            <span className="grid size-10 shrink-0 place-items-center border border-primary/15 bg-primary-tint text-primary">
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            </span>
                            <div>
                                <h2 className="text-base font-semibold">
                                    ZRA documents
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Fiscal receipts and statements
                                </p>
                            </div>
                        </div>
                        <div className="space-y-3 p-4 md:p-5">
                            <ZraDocumentsSummary documents={zraDocuments} />
                            <div className="flex flex-wrap items-center gap-3">
                                <Button variant="outline" asChild>
                                    <Link href={zraDocuments.statementUrl}>
                                        <FileText aria-hidden="true" />
                                        Group statement
                                    </Link>
                                </Button>
                                <GeneralReceiptDialog
                                    preview={generalReceipt}
                                />
                            </div>
                        </div>
                    </section>
                )}
                <VfmsFilingsPanel
                    filings={vfmsFilings}
                    closeUrl={vfmsCloseWithoutFilingUrl}
                    fiscalExceptionReason={vfmsFiscalExceptionReason}
                />
                <Collapsible className="border border-border bg-card p-4 shadow-panel md:p-5">
                    <h2>
                        <CollapsibleTrigger asChild>
                            <Button
                                variant="ghost"
                                className="group h-auto min-h-11 w-full max-w-full justify-between text-left text-lg font-semibold whitespace-normal"
                            >
                                <span className="inline-flex items-center gap-2">
                                    <FileText
                                        aria-hidden="true"
                                        className="size-5 text-primary"
                                    />
                                    Amendment history ({amendments.total})
                                </span>
                                <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                            </Button>
                        </CollapsibleTrigger>
                    </h2>
                    <CollapsibleContent className="border-t border-border pt-4">
                        <AmendmentHistory amendments={amendments} />
                    </CollapsibleContent>
                </Collapsible>
                {groupMembers.length > 0 && (
                    <Collapsible
                        defaultOpen
                        className="border border-border bg-card p-4 shadow-panel md:p-5"
                    >
                        <h2>
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="group h-auto min-h-11 w-full max-w-full justify-between text-left text-lg font-semibold whitespace-normal"
                                >
                                    <span className="inline-flex items-center gap-2">
                                        <UsersRound
                                            aria-hidden="true"
                                            className="size-5 text-primary"
                                        />
                                        Other reservations in this booking group
                                        ({groupMembers.length})
                                    </span>
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                                </Button>
                            </CollapsibleTrigger>
                        </h2>
                        <CollapsibleContent className="border-t border-border pt-4">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            Reservation number
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Arrival</TableHead>
                                        <TableHead>Departure</TableHead>
                                        <TableHead>Actions</TableHead>
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
                                                        reservationStatusTones[
                                                            member.status
                                                        ] ?? 'unavailable'
                                                    }
                                                >
                                                    {reservationStatusLabels[
                                                        member.status
                                                    ] ??
                                                        member.status.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
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
                                            <TableCell>
                                                <GroupMemberActions
                                                    member={member}
                                                    acceptedPaymentMethods={
                                                        acceptedPaymentMethods
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CollapsibleContent>
                    </Collapsible>
                )}
                <Collapsible className="border border-border bg-card p-4 shadow-panel md:p-5">
                    <h2>
                        <CollapsibleTrigger asChild>
                            <Button
                                variant="ghost"
                                className="group h-auto min-h-11 w-full max-w-full justify-between text-left text-lg font-semibold whitespace-normal"
                            >
                                <span className="inline-flex items-center gap-2">
                                    <CalendarDays
                                        aria-hidden="true"
                                        className="size-5 text-primary"
                                    />
                                    Fixed nightly prices (
                                    {reservation.price_lines.length})
                                </span>
                                <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                            </Button>
                        </CollapsibleTrigger>
                    </h2>
                    <CollapsibleContent className="border-t border-border pt-4">
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

Show.layout = {
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        { title: 'Reservation details', href: '#' },
    ],
};
