import { useState } from 'react';
import { Form, Head, router } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { create, store } from '@/routes/reservations';

type Stay = {
    room_type_id: string;
    rate_plan_id: string;
    arrival: string;
    departure: string;
    adults: number;
    children: number;
    rooms?: number;
    source?: string;
};
type Named = { id: string; name: string };

export default function Create({
    stay,
    roomType,
    ratePlan,
    rooms,
    restrictionWarnings,
    nightlyRate,
    businessDate,
}: {
    stay: Stay;
    roomType: Named;
    ratePlan: Named & { currency: string };
    rooms: { id: string; number: string; isReady: boolean }[];
    restrictionWarnings: string[];
    nightlyRate: number | null;
    businessDate: string;
}) {
    const [source, setSource] = useState(stay.source ?? 'direct');
    const [values, setValues] = useState({
        arrival: source === 'walk-in' ? businessDate : stay.arrival,
        departure: stay.departure,
        adults: stay.adults.toString(),
        children: stay.children.toString(),
    });
    const [operator, setOperator] = useState(false);
    const [documentType, setDocumentType] = useState('');
    const [collector, setCollector] = useState('property');
    function refreshStay(input: HTMLInputElement) {
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
                room_type_id: stay.room_type_id,
                rate_plan_id: stay.rate_plan_id,
                arrival,
                departure,
                adults,
                children,
            },
            {
                only: ['rooms', 'nightlyRate', 'restrictionWarnings'],
                preserveState: true,
                preserveScroll: true,
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
                        {roomType.name} · {ratePlan.name}. Prices and
                        availability are checked again when you confirm.
                    </p>
                </header>
                <Form
                    {...store.form()}
                    className="flex max-w-[720px] flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <PropertyIntent />
                            <input
                                type="hidden"
                                name="room_type_id"
                                value={stay.room_type_id}
                            />
                            <input
                                type="hidden"
                                name="rate_plan_id"
                                value={stay.rate_plan_id}
                            />
                            {Object.entries(errors).map(([name, message]) => (
                                <InputError key={name} message={message} />
                            ))}
                            <fieldset className="space-y-4">
                                <legend className="font-medium">
                                    Lead guest
                                </legend>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {(
                                        [
                                            ['first_name', 'First name'],
                                            ['last_name', 'Last name'],
                                            ['email', 'Email'],
                                            ['phone', 'Phone'],
                                            [
                                                'nationality',
                                                'Country (two-letter code)',
                                            ],
                                        ] as const
                                    ).map(([name, label]) => (
                                        <div key={name} className="space-y-2">
                                            <Label htmlFor={`guest-${name}`}>
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
                                                        source !== 'walk-in')
                                                }
                                                maxLength={
                                                    name === 'nationality'
                                                        ? 2
                                                        : name === 'phone'
                                                          ? 50
                                                          : name.endsWith(
                                                                  'name',
                                                              )
                                                            ? 120
                                                            : 255
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `lead_guest.${name}`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ))}
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
                                    <option value="passport">Passport</option>
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
                            </fieldset>
                            <fieldset className="space-y-4">
                                <legend className="font-medium">Stay</legend>
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
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="email">Email</option>
                                    <option value="website">Our website</option>
                                    <option value="outside-integration">
                                        Booking received outside integration
                                    </option>
                                </NativeSelect>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {(
                                        [
                                            'arrival',
                                            'departure',
                                            'adults',
                                            'children',
                                        ] as const
                                    ).map((name) => (
                                        <div key={name} className="space-y-2">
                                            <Label htmlFor={name}>
                                                {
                                                    {
                                                        arrival:
                                                            'Check-in date',
                                                        departure:
                                                            'Check-out date',
                                                        adults: 'Adults',
                                                        children: 'Children',
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
                                                type={
                                                    name === 'arrival' ||
                                                    name === 'departure'
                                                        ? 'date'
                                                        : 'number'
                                                }
                                                required
                                                min={
                                                    name === 'adults'
                                                        ? 1
                                                        : name === 'children'
                                                          ? 0
                                                          : undefined
                                                }
                                                max={
                                                    name === 'adults' ||
                                                    name === 'children'
                                                        ? 20
                                                        : undefined
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                                <Label htmlFor="rooms">Rooms</Label>
                                <Input
                                    id="rooms"
                                    name="rooms"
                                    type="number"
                                    min={1}
                                    max={20}
                                    defaultValue={stay.rooms ?? 1}
                                    required
                                />
                                <p className="text-sm text-muted-foreground">
                                    Several rooms create a booking group. Guest
                                    details and prices apply to each room; the
                                    assignment applies to the first. Free rooms
                                    refresh when you finish editing the stay.
                                </p>
                                <Label htmlFor="room_id">
                                    Specific room (optional)
                                </Label>
                                <NativeSelect id="room_id" name="room_id">
                                    <option value="">Leave unassigned</option>
                                    {rooms.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.number} —{' '}
                                            {room.isReady
                                                ? 'Ready'
                                                : 'Not ready'}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <Label htmlFor="nightly_rate">
                                    Own nightly rate ({ratePlan.currency}, minor
                                    units)
                                </Label>
                                <Input
                                    id="nightly_rate"
                                    name="nightly_rate"
                                    type="number"
                                    min={0}
                                    max={1000000000000}
                                    placeholder={nightlyRate?.toString()}
                                />
                                <p className="text-sm text-muted-foreground">
                                    Leave blank to use the rate plan's price for
                                    each night. First night:{' '}
                                    {nightlyRate ?? 'Unavailable'}{' '}
                                    {ratePlan.currency} minor units.
                                </p>
                                <Label htmlFor="meal_plan">Meal plan</Label>
                                <NativeSelect id="meal_plan" name="meal_plan">
                                    <option value="room-only">Room only</option>
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
                                    value={operator ? 'tour-operator' : 'guest'}
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
                                                Room, levy and other services
                                            </option>
                                            <option value="room-and-other-services">
                                                Room and other services
                                            </option>
                                        </NativeSelect>
                                    </>
                                )}
                                <Label htmlFor="special_requests">
                                    Special requests
                                </Label>
                                <Input
                                    id="special_requests"
                                    name="special_requests"
                                    maxLength={5000}
                                />
                            </fieldset>
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
                                <option value="property">Property</option>
                                <option value="channel">Channel</option>
                            </NativeSelect>
                            {collector === 'channel' && (
                                <>
                                    <Label htmlFor="channel_collected_amount">
                                        Channel-collected amount (minor units)
                                    </Label>
                                    <Input
                                        id="channel_collected_amount"
                                        name="channel_collected_amount"
                                        type="number"
                                        min={0}
                                        max={1000000000000}
                                        required
                                    />
                                </>
                            )}
                            {(restrictionWarnings.length > 0 ||
                                errors.restriction_override_reason) && (
                                <div className="space-y-3">
                                    <ul className="list-disc pl-4">
                                        {restrictionWarnings.map((warning) => (
                                            <li key={warning}>{warning}</li>
                                        ))}
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
                                className="self-end"
                            >
                                {processing && <Spinner />}Confirm reservation
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
