import { useState } from 'react';
import { Form, Head, router } from '@inertiajs/react';
import { CountrySelect } from '@/components/shared/country-select';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { AdultGuestFields } from '@/features/guests/adult-guest-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { NativeSelect } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatMoney } from '@/lib/utils';
import { create, index, store } from '@/routes/reservations';

type Stay = {
    room_id?: string;
    room_type_id?: string;
    rate_plan_id?: string;
    arrival: string;
    departure: string;
    adults: number;
    children: number;
    rooms?: number;
    lines?: Line[];
    source?: string;
};
type Line = {
    room_type_id: string;
    rate_plan_id: string;
    rooms: number | string;
    room_ids?: (string | null)[];
};
type Named = { id: string; name: string };

export default function Create({
    stay,
    roomType,
    roomTypes = [],
    ratePlan,
    ratePlans,
    rooms,
    restrictionWarnings,
    nightlyRate,
    businessDate,
    guestAllocation = [],
    filesWithVfms,
}: {
    stay: Stay;
    roomType: Named | null;
    roomTypes?: Named[];
    ratePlan: (Named & { currency: string }) | null;
    ratePlans: (Named & { currency: string; room_type_ids: string[] })[];
    rooms: {
        id: string;
        number: string;
        room_type_id: string;
        isReady: boolean;
    }[];
    restrictionWarnings: string[];
    nightlyRate: number | null;
    businessDate: string;
    guestAllocation?: { adults: number; children: number }[];
    filesWithVfms: boolean;
}) {
    const [source, setSource] = useState(stay.source ?? 'direct');
    const [values, setValues] = useState({
        arrival: source === 'walk-in' ? businessDate : stay.arrival,
        departure: stay.departure,
        adults: stay.adults.toString(),
        children: stay.children.toString(),
    });
    // One line per room type; each line books `rooms` rooms of that type.
    const [lines, setLines] = useState<Line[]>(
        () =>
            stay.lines ?? [
                {
                    room_type_id:
                        stay.room_type_id ??
                        rooms.find((room) => room.id === stay.room_id)
                            ?.room_type_id ??
                        '',
                    rate_plan_id: ratePlan?.id ?? '',
                    rooms: stay.rooms ?? 1,
                    room_ids: [stay.room_id ?? null],
                },
            ],
    );
    const [ownRate, setOwnRate] = useState(false);
    const plansFor = (typeId: string) =>
        ratePlans.filter((plan) => plan.room_type_ids.includes(typeId));
    function updateLine(
        index: number,
        change: Partial<Line>,
        refresh?: HTMLInputElement | HTMLSelectElement,
    ) {
        const next = lines.map((line, other) =>
            other === index ? { ...line, ...change } : line,
        );
        setLines(next);
        if (refresh) refreshStay(refresh, next);
    }
    const totalRooms = lines.reduce(
        (total, line) => total + (Number(line.rooms) || 0),
        0,
    );
    const [operator, setOperator] = useState(false);
    const [institution, setInstitution] = useState('');
    const [documentType, setDocumentType] = useState('');
    const [collector, setCollector] = useState('property');
    const currencies = [
        ...new Set(
            lines.flatMap(
                (line) =>
                    ratePlans.find((plan) => plan.id === line.rate_plan_id)
                        ?.currency ?? [],
            ),
        ),
    ];
    // The money fields follow the first room's plan; the server refuses an own rate across currencies.
    const currency = currencies[0] ?? ratePlan?.currency ?? 'USD';
    const nights =
        values.arrival && values.departure > values.arrival
            ? (Date.parse(values.departure) - Date.parse(values.arrival)) /
              86_400_000
            : null;
    function refreshStay(
        input: HTMLInputElement | HTMLSelectElement,
        nextLines = lines,
    ) {
        if (!input.form) return;
        const data = new FormData(input.form);
        const arrival = data.get('arrival');
        const departure = data.get('departure');
        const adults = data.get('adults');
        const children = data.get('children');
        if (
            typeof arrival !== 'string' ||
            typeof departure !== 'string' ||
            typeof adults !== 'string' ||
            typeof children !== 'string' ||
            !arrival ||
            departure <= arrival
        )
            return;
        router.get(
            create.url(),
            {
                lines: nextLines,
                arrival,
                departure,
                adults,
                children,
                distribute_guests: 1,
            },
            {
                only: [
                    'stay',
                    'roomType',
                    'ratePlan',
                    'rooms',
                    'nightlyRate',
                    'restrictionWarnings',
                    'guestAllocation',
                ],
                preserveState: true,
                preserveScroll: true,
                // Keep line indexes so each room line stays together.
                queryStringArrayFormat: 'indices',
            },
        );
    }
    return (
        <>
            <Head title="New reservation" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold">New reservation</h1>
                    <p className="text-sm text-muted-foreground">
                        {totalRooms === 1 ? '1 room' : `${totalRooms} rooms`}.
                        Prices and availability are checked again when you
                        confirm.
                    </p>
                </header>
                <Form
                    {...store.form()}
                    className="@container flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <input
                                type="hidden"
                                name="distribute_guests"
                                value="1"
                            />
                            {Object.entries(errors).map(([name, message]) => (
                                <InputError key={name} message={message} />
                            ))}
                            {/* Sections sit side by side on wide screens; each stays at most 720px. */}
                            <div className="grid max-w-[720px] gap-6 @3xl:max-w-none @3xl:grid-cols-[minmax(0,720px)_288px] @6xl:grid-cols-[minmax(0,720px)_minmax(0,720px)_320px]">
                                <fieldset className="space-y-4">
                                    <legend className="font-medium">
                                        Guests
                                    </legend>
                                    <h3 className="text-sm font-medium">
                                        Lead guest (required)
                                    </h3>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {(
                                            [
                                                ['first_name', 'First name'],
                                                ['last_name', 'Last name'],
                                                ['email', 'Email'],
                                                ['phone', 'Phone'],
                                            ] as const
                                        ).map(([name, label]) => (
                                            <div
                                                key={name}
                                                className="space-y-2"
                                            >
                                                <Label
                                                    htmlFor={`guest-${name}`}
                                                >
                                                    {label}
                                                </Label>
                                                <Input
                                                    id={`guest-${name}`}
                                                    name={`lead_guest[${name}]`}
                                                    type={
                                                        name === 'email'
                                                            ? 'email'
                                                            : name === 'phone'
                                                              ? 'tel'
                                                              : 'text'
                                                    }
                                                    required={
                                                        name === 'first_name' ||
                                                        (name === 'last_name' &&
                                                            source !==
                                                                'walk-in')
                                                    }
                                                    maxLength={
                                                        name === 'phone'
                                                            ? 50
                                                            : name.endsWith(
                                                                    'name',
                                                                )
                                                              ? 120
                                                              : 255
                                                    }
                                                />
                                            </div>
                                        ))}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="guest-nationality">
                                            Country
                                        </Label>
                                        <CountrySelect
                                            id="guest-nationality"
                                            name="lead_guest[nationality]"
                                            aria-invalid={
                                                !!errors[
                                                    'lead_guest.nationality'
                                                ]
                                            }
                                        />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        ID is needed at check-in. Add it now or
                                        later; walk-ins follow the property's ID
                                        requirement. Foreign guests may need a
                                        passport.
                                    </p>
                                    <Label htmlFor="document_type">
                                        ID document (optional)
                                    </Label>
                                    <NativeSelect
                                        id="document_type"
                                        name="lead_guest[document_type]"
                                        value={documentType}
                                        onChange={(event) =>
                                            setDocumentType(event.target.value)
                                        }
                                    >
                                        <option value="">Add later</option>
                                        <option value="passport">
                                            Passport
                                        </option>
                                        <option value="nida">NIDA</option>
                                        <option value="zanid">ZanID</option>
                                    </NativeSelect>
                                    {documentType && (
                                        <div className="space-y-2">
                                            <Label htmlFor="document_number">
                                                Document number
                                            </Label>
                                            <Input
                                                id="document_number"
                                                name="lead_guest[document_number]"
                                                required
                                                maxLength={255}
                                            />
                                        </div>
                                    )}
                                    {Array.from(
                                        {
                                            length: Math.min(
                                                19,
                                                Math.max(
                                                    0,
                                                    Number(values.adults) - 1,
                                                ),
                                            ),
                                        },
                                        (_, index) => (
                                            <fieldset
                                                key={index}
                                                className="space-y-4 rounded-xl border p-4"
                                            >
                                                <legend className="px-1 text-sm font-medium">
                                                    Adult {index + 2} (optional
                                                    now)
                                                </legend>
                                                <p className="text-sm text-muted-foreground">
                                                    Leave blank to add at
                                                    check-in. Every adult must
                                                    be registered before
                                                    check-in; children do not
                                                    need ID.
                                                </p>
                                                <AdultGuestFields
                                                    id={`guest-${index}`}
                                                    names={{
                                                        name: `guests[${index}][name]`,
                                                        nationality: `guests[${index}][nationality]`,
                                                        document_type: `guests[${index}][document_type]`,
                                                        document_number: `guests[${index}][document_number]`,
                                                    }}
                                                    errors={{
                                                        name: errors[
                                                            `guests.${index}.name`
                                                        ],
                                                        nationality:
                                                            errors[
                                                                `guests.${index}.nationality`
                                                            ],
                                                        document_type:
                                                            errors[
                                                                `guests.${index}.document_type`
                                                            ],
                                                        document_number:
                                                            errors[
                                                                `guests.${index}.document_number`
                                                            ],
                                                    }}
                                                />
                                            </fieldset>
                                        ),
                                    )}
                                </fieldset>
                                <fieldset className="space-y-4 @6xl:col-start-2 @6xl:row-[1/span_2]">
                                    <legend className="font-medium">
                                        Stay
                                    </legend>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {(
                                            ['arrival', 'departure'] as const
                                        ).map((name) => (
                                            <div
                                                key={name}
                                                className="space-y-2"
                                            >
                                                <Label htmlFor={name}>
                                                    {
                                                        {
                                                            arrival:
                                                                'Check-in date',
                                                            departure:
                                                                'Check-out date',
                                                        }[name]
                                                    }
                                                </Label>
                                                <Input
                                                    id={name}
                                                    name={name}
                                                    value={values[name]}
                                                    onChange={(event) =>
                                                        setValues((values) => ({
                                                            ...values,
                                                            [name]: event.target
                                                                .value,
                                                        }))
                                                    }
                                                    onBlur={(event) =>
                                                        refreshStay(
                                                            event.currentTarget,
                                                        )
                                                    }
                                                    type="date"
                                                    required
                                                />
                                            </div>
                                        ))}
                                    </div>
                                    {lines.map((line, index) => {
                                        const typeName =
                                            roomTypes.find(
                                                (type) =>
                                                    type.id ===
                                                    line.room_type_id,
                                            )?.name ?? 'Room type';
                                        // Rooms chosen anywhere on the form; each select still shows its own choice.
                                        const picked = lines.flatMap(
                                            (other) => other.room_ids ?? [],
                                        );
                                        const prefix = `lines[${index}]`;
                                        return (
                                            <fieldset
                                                key={index}
                                                className="space-y-3 rounded-lg border p-3"
                                                aria-label={`Room line ${index + 1}`}
                                            >
                                                <div className="grid gap-3 sm:grid-cols-[1fr_6rem]">
                                                    <div className="space-y-2">
                                                        <Label
                                                            htmlFor={`${prefix}-type`}
                                                        >
                                                            Room type
                                                        </Label>
                                                        <NativeSelect
                                                            id={`${prefix}-type`}
                                                            name={`${prefix}[room_type_id]`}
                                                            value={
                                                                line.room_type_id
                                                            }
                                                            required
                                                            onChange={(event) =>
                                                                updateLine(
                                                                    index,
                                                                    {
                                                                        room_type_id:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        rate_plan_id:
                                                                            plansFor(
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )[0]
                                                                                ?.id ??
                                                                            '',
                                                                        room_ids:
                                                                            [],
                                                                    },
                                                                    event.currentTarget,
                                                                )
                                                            }
                                                        >
                                                            <option value="">
                                                                Select room type
                                                            </option>
                                                            {roomTypes.map(
                                                                (type) => (
                                                                    <option
                                                                        key={
                                                                            type.id
                                                                        }
                                                                        value={
                                                                            type.id
                                                                        }
                                                                    >
                                                                        {
                                                                            type.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </NativeSelect>
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label
                                                            htmlFor={`${prefix}-rooms`}
                                                        >
                                                            Rooms
                                                        </Label>
                                                        <Input
                                                            id={`${prefix}-rooms`}
                                                            name={`${prefix}[rooms]`}
                                                            type="number"
                                                            min={1}
                                                            max={20}
                                                            required
                                                            value={line.rooms}
                                                            onChange={(event) =>
                                                                updateLine(
                                                                    index,
                                                                    {
                                                                        rooms: event
                                                                            .target
                                                                            .value,
                                                                        room_ids:
                                                                            (
                                                                                line.room_ids ??
                                                                                []
                                                                            ).slice(
                                                                                0,
                                                                                Number(
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                ) ||
                                                                                    0,
                                                                            ),
                                                                    },
                                                                )
                                                            }
                                                            onBlur={(event) =>
                                                                refreshStay(
                                                                    event.currentTarget,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                                <Label
                                                    htmlFor={`${prefix}-plan`}
                                                >
                                                    Rate plan
                                                </Label>
                                                <NativeSelect
                                                    id={`${prefix}-plan`}
                                                    name={`${prefix}[rate_plan_id]`}
                                                    value={line.rate_plan_id}
                                                    required
                                                    onChange={(event) =>
                                                        updateLine(
                                                            index,
                                                            {
                                                                rate_plan_id:
                                                                    event.target
                                                                        .value,
                                                            },
                                                            event.currentTarget,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Select rate plan
                                                    </option>
                                                    {plansFor(
                                                        line.room_type_id,
                                                    ).map((plan) => (
                                                        <option
                                                            key={plan.id}
                                                            value={plan.id}
                                                        >
                                                            {plan.name} (
                                                            {plan.currency})
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                                <InputError
                                                    message={
                                                        errors[
                                                            `lines.${index}.rate_plan_id`
                                                        ]
                                                    }
                                                />
                                                {line.room_type_id &&
                                                    Array.from(
                                                        {
                                                            length: Math.min(
                                                                Number(
                                                                    line.rooms,
                                                                ) || 0,
                                                                20,
                                                            ),
                                                        },
                                                        (_, room) => {
                                                            const value =
                                                                line.room_ids?.[
                                                                    room
                                                                ] ?? '';
                                                            return (
                                                                <div
                                                                    key={room}
                                                                    className="space-y-2"
                                                                >
                                                                    <Label
                                                                        htmlFor={`${prefix}-room-${room}`}
                                                                    >
                                                                        {
                                                                            typeName
                                                                        }{' '}
                                                                        {room +
                                                                            1}{' '}
                                                                        room
                                                                        (optional)
                                                                    </Label>
                                                                    <NativeSelect
                                                                        id={`${prefix}-room-${room}`}
                                                                        name={`${prefix}[room_ids][${room}]`}
                                                                        value={
                                                                            value
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) => {
                                                                            const ids =
                                                                                [
                                                                                    ...(line.room_ids ??
                                                                                        []),
                                                                                ];
                                                                            ids[
                                                                                room
                                                                            ] =
                                                                                event
                                                                                    .target
                                                                                    .value ||
                                                                                null;
                                                                            updateLine(
                                                                                index,
                                                                                {
                                                                                    room_ids:
                                                                                        ids,
                                                                                },
                                                                            );
                                                                        }}
                                                                    >
                                                                        <option value="">
                                                                            Leave
                                                                            unassigned
                                                                        </option>
                                                                        {rooms
                                                                            .filter(
                                                                                (
                                                                                    option,
                                                                                ) =>
                                                                                    option.room_type_id ===
                                                                                        line.room_type_id &&
                                                                                    (option.id ===
                                                                                        value ||
                                                                                        !picked.includes(
                                                                                            option.id,
                                                                                        )),
                                                                            )
                                                                            .map(
                                                                                (
                                                                                    option,
                                                                                ) => (
                                                                                    <option
                                                                                        key={
                                                                                            option.id
                                                                                        }
                                                                                        value={
                                                                                            option.id
                                                                                        }
                                                                                    >
                                                                                        {
                                                                                            option.number
                                                                                        }{' '}
                                                                                        —{' '}
                                                                                        {option.isReady
                                                                                            ? 'Ready'
                                                                                            : 'Not ready'}
                                                                                    </option>
                                                                                ),
                                                                            )}
                                                                    </NativeSelect>
                                                                </div>
                                                            );
                                                        },
                                                    )}
                                                {lines.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setLines(
                                                                lines.filter(
                                                                    (
                                                                        _,
                                                                        other,
                                                                    ) =>
                                                                        other !==
                                                                        index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Remove {typeName}
                                                    </Button>
                                                )}
                                            </fieldset>
                                        );
                                    })}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setLines([
                                                ...lines,
                                                {
                                                    room_type_id: '',
                                                    rate_plan_id: '',
                                                    rooms: 1,
                                                    room_ids: [],
                                                },
                                            ])
                                        }
                                    >
                                        Add another room type
                                    </Button>
                                    <p className="text-sm text-muted-foreground">
                                        Enter total adults and children for all
                                        rooms. Each room needs an adult and must
                                        fit its room type's capacity. Several
                                        rooms create a booking group, priced
                                        separately. Assign rooms now or leave
                                        them unassigned until check-in.
                                    </p>
                                    <InputError message={errors.rooms} />
                                    {guestAllocation.length > 1 && (
                                        <ul
                                            className="text-sm text-muted-foreground"
                                            aria-label="Guests per room"
                                        >
                                            {guestAllocation.map(
                                                (occupancy, index) => (
                                                    <li key={index}>
                                                        Room {index + 1}:{' '}
                                                        {occupancy.adults}{' '}
                                                        adults,{' '}
                                                        {occupancy.children}{' '}
                                                        children
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                    {rooms.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No rooms are free for these dates.
                                            Change the stay dates.
                                        </p>
                                    )}
                                    <div className="flex items-center gap-2">
                                        <input
                                            id="own-rate"
                                            type="checkbox"
                                            className="size-4 rounded-sm"
                                            checked={ownRate}
                                            onChange={(event) =>
                                                setOwnRate(event.target.checked)
                                            }
                                        />
                                        <Label htmlFor="own-rate">
                                            Own nightly rate
                                        </Label>
                                    </div>
                                    <fieldset
                                        hidden={!ownRate}
                                        disabled={!ownRate}
                                    >
                                        <Label htmlFor="nightly_rate">
                                            Own nightly rate ({currency})
                                        </Label>
                                        <MoneyInput
                                            id="nightly_rate"
                                            name="nightly_rate"
                                            currency={currency}
                                            min={0}
                                            max={1000000000000}
                                            placeholder={
                                                nightlyRate ?? undefined
                                            }
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            Leave blank to use the rate plan's
                                            price for each night. First room,
                                            first night:{' '}
                                            {nightlyRate === null
                                                ? 'Unavailable'
                                                : formatMoney(
                                                      nightlyRate,
                                                      currency,
                                                  )}
                                            .
                                        </p>
                                    </fieldset>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {(['adults', 'children'] as const).map(
                                            (name) => (
                                                <div
                                                    key={name}
                                                    className="space-y-2"
                                                >
                                                    <Label htmlFor={name}>
                                                        {name === 'adults'
                                                            ? 'Total adults'
                                                            : 'Total children'}
                                                    </Label>
                                                    <Input
                                                        id={name}
                                                        name={name}
                                                        type="number"
                                                        min={
                                                            name === 'adults'
                                                                ? 1
                                                                : 0
                                                        }
                                                        max={20}
                                                        required
                                                        value={values[name]}
                                                        onChange={(event) =>
                                                            setValues(
                                                                (values) => ({
                                                                    ...values,
                                                                    [name]: event
                                                                        .target
                                                                        .value,
                                                                }),
                                                            )
                                                        }
                                                        onBlur={(event) =>
                                                            refreshStay(
                                                                event.currentTarget,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </fieldset>
                                <fieldset className="space-y-4">
                                    <legend className="font-medium">
                                        Booked through
                                    </legend>
                                    <Label htmlFor="booked_through">
                                        Who books and pays
                                    </Label>
                                    <NativeSelect
                                        id="booked_through"
                                        name="booked_through"
                                        value={
                                            operator ? 'tour-operator' : 'guest'
                                        }
                                        onChange={(event) =>
                                            setOperator(
                                                event.target.value ===
                                                    'tour-operator',
                                            )
                                        }
                                    >
                                        <option value="guest">
                                            Guest directly
                                        </option>
                                        <option value="tour-operator">
                                            Tour operator
                                        </option>
                                    </NativeSelect>
                                    {operator && (
                                        <>
                                            <Label htmlFor="tour_operator_name">
                                                Tour operator name
                                            </Label>
                                            <Input
                                                id="tour_operator_name"
                                                name="tour_operator_name"
                                                required
                                                maxLength={255}
                                            />
                                            <Label htmlFor="tour_operator_registration">
                                                ZRA registration number
                                            </Label>
                                            <Input
                                                id="tour_operator_registration"
                                                name="tour_operator_registration"
                                                required
                                                maxLength={255}
                                            />
                                            <Label htmlFor="tour_operator_pays_for">
                                                Pays for
                                            </Label>
                                            <NativeSelect
                                                id="tour_operator_pays_for"
                                                name="tour_operator_pays_for"
                                            >
                                                <option value="room-and-levy">
                                                    Room and levy
                                                </option>
                                                <option value="room-only">
                                                    Room only
                                                </option>
                                                <option value="room-levy-and-other-services">
                                                    Room, levy and other
                                                    services
                                                </option>
                                                <option value="room-and-other-services">
                                                    Room and other services
                                                </option>
                                            </NativeSelect>
                                        </>
                                    )}
                                    <Label htmlFor="meal_plan">Meal plan</Label>
                                    <NativeSelect
                                        id="meal_plan"
                                        name="meal_plan"
                                    >
                                        <option value="room-only">
                                            Room only
                                        </option>
                                        <option value="bed-and-breakfast">
                                            Bed and breakfast
                                        </option>
                                        <option value="half-board">
                                            Half board
                                        </option>
                                        <option value="full-board">
                                            Full board
                                        </option>
                                        <option value="all-inclusive">
                                            All-inclusive
                                        </option>
                                    </NativeSelect>
                                    <Label htmlFor="source">Source</Label>
                                    <NativeSelect
                                        id="source"
                                        name="source"
                                        value={source}
                                        onChange={(event) => {
                                            const source = event.target.value;
                                            setSource(source);
                                            if (source === 'walk-in')
                                                setValues((values) => ({
                                                    ...values,
                                                    arrival: businessDate,
                                                }));
                                        }}
                                    >
                                        <option value="direct">Direct</option>
                                        <option value="walk-in">Walk-in</option>
                                        <option value="phone">Phone</option>
                                        <option value="whatsapp">
                                            WhatsApp
                                        </option>
                                        <option value="email">Email</option>
                                        <option value="website">
                                            Our website
                                        </option>
                                        <option value="outside-integration">
                                            Booking received outside integration
                                        </option>
                                    </NativeSelect>
                                    <Label htmlFor="special_requests">
                                        Special requests
                                    </Label>
                                    <Input
                                        id="special_requests"
                                        name="special_requests"
                                        maxLength={5000}
                                    />
                                </fieldset>
                                {filesWithVfms && (
                                    <fieldset className="space-y-4">
                                        <legend className="font-medium">
                                            Paid by an institution
                                        </legend>
                                        <Label htmlFor="paying_institution_type">
                                            Paying institution
                                        </Label>
                                        <NativeSelect
                                            id="paying_institution_type"
                                            name="paying_institution_type"
                                            value={institution}
                                            onChange={(event) =>
                                                setInstitution(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">None</option>
                                            <option value="government">
                                                Government institution
                                                (withholding)
                                            </option>
                                            <option value="business">
                                                Registered business (B2B)
                                            </option>
                                        </NativeSelect>
                                        {institution && (
                                            <>
                                                <Label htmlFor="paying_institution_name">
                                                    Institution name
                                                </Label>
                                                <Input
                                                    id="paying_institution_name"
                                                    name="paying_institution_name"
                                                    required
                                                    maxLength={255}
                                                />
                                                <Label htmlFor="paying_institution_zrb_number">
                                                    ZRB number
                                                </Label>
                                                <Input
                                                    id="paying_institution_zrb_number"
                                                    name="paying_institution_zrb_number"
                                                    required
                                                    maxLength={255}
                                                />
                                            </>
                                        )}
                                    </fieldset>
                                )}
                                {source === 'outside-integration' && (
                                    <fieldset className="space-y-4">
                                        <legend className="font-medium">
                                            Reconciliation details
                                        </legend>
                                        <Label htmlFor="channel_reference">
                                            Channel reference
                                        </Label>
                                        <Input
                                            id="channel_reference"
                                            name="channel_reference"
                                            required
                                            maxLength={255}
                                        />
                                        <Label htmlFor="outside_integration_reason">
                                            Reason received outside integration
                                        </Label>
                                        <Input
                                            id="outside_integration_reason"
                                            name="outside_integration_reason"
                                            required
                                            maxLength={2000}
                                        />
                                    </fieldset>
                                )}
                                <aside className="flex flex-col gap-4 self-start rounded-xl border bg-card p-4 @3xl:sticky @3xl:top-20 @3xl:col-start-2 @3xl:row-[1/span_3] @6xl:col-start-3 @6xl:row-[1/span_2]">
                                    <h2 className="font-medium">Summary</h2>
                                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm [&_dd]:text-right [&_dt]:text-muted-foreground">
                                        <dt>Rooms</dt>
                                        <dd>
                                            {lines
                                                .filter(
                                                    (line) => line.room_type_id,
                                                )
                                                .map(
                                                    (line) =>
                                                        `${line.rooms} × ${roomTypes.find((type) => type.id === line.room_type_id)?.name ?? roomType?.name ?? 'Room'}`,
                                                )
                                                .join(', ') || 'Not chosen'}
                                        </dd>
                                        <dt>Dates</dt>
                                        <dd className="tabular-nums">
                                            {nights === null
                                                ? 'Not set'
                                                : `${formatDate(values.arrival)} to ${formatDate(values.departure)}`}
                                        </dd>
                                        <dt>Nights</dt>
                                        <dd className="tabular-nums">
                                            {nights ?? 'Not set'}
                                        </dd>
                                        <dt>Rate plan</dt>
                                        <dd>
                                            {[
                                                ...new Set(
                                                    lines.flatMap(
                                                        (line) =>
                                                            ratePlans.find(
                                                                (plan) =>
                                                                    plan.id ===
                                                                    line.rate_plan_id,
                                                            )?.name ?? [],
                                                    ),
                                                ),
                                            ].join(', ') || 'Not chosen'}
                                        </dd>
                                        <dt>First room, first night</dt>
                                        <dd className="tabular-nums">
                                            {nightlyRate === null
                                                ? 'Unavailable'
                                                : formatMoney(
                                                      nightlyRate,
                                                      currency,
                                                  )}
                                        </dd>
                                    </dl>
                                    <div className="space-y-2">
                                        <Label htmlFor="payment_collector">
                                            Payment collector
                                        </Label>
                                        <NativeSelect
                                            id="payment_collector"
                                            name="payment_collector"
                                            value={collector}
                                            onChange={(event) =>
                                                setCollector(event.target.value)
                                            }
                                        >
                                            <option value="property">
                                                Property
                                            </option>
                                            <option value="channel">
                                                Channel
                                            </option>
                                        </NativeSelect>
                                    </div>
                                    {collector === 'channel' && (
                                        <div className="space-y-2">
                                            <Label htmlFor="channel_collected_amount">
                                                Channel-collected amount (
                                                {currency})
                                            </Label>
                                            <MoneyInput
                                                id="channel_collected_amount"
                                                name="channel_collected_amount"
                                                currency={currency}
                                                min={0}
                                                max={1000000000000}
                                                required
                                            />
                                        </div>
                                    )}
                                    {(restrictionWarnings.length > 0 ||
                                        errors.restriction_override_reason) && (
                                        <div className="space-y-3">
                                            <ul className="list-disc pl-4">
                                                {restrictionWarnings.map(
                                                    (warning) => (
                                                        <li key={warning}>
                                                            {warning}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                            <Label htmlFor="restriction_override_reason">
                                                Restriction override reason
                                            </Label>
                                            <Input
                                                id="restriction_override_reason"
                                                name="restriction_override_reason"
                                                required
                                                maxLength={2000}
                                            />
                                        </div>
                                    )}
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full"
                                    >
                                        {processing && <Spinner />}Confirm
                                        reservation
                                    </Button>
                                </aside>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Create.layout = {
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        { title: 'New reservation', href: create() },
    ],
};
