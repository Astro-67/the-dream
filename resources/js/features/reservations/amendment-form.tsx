import { Pagination } from '@/components/ui/pagination';
import type { Paginated } from '@/types';
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { amend } from '@/routes/reservations';

type Named = { id: string; name: string };
export type AmendmentOptions = {
    roomTypes: Named[];
    ratePlans: (Named & { room_types: { id: string }[] })[];
    rooms: { id: string; number: string; room_type_id: string }[];
};
type Stay = {
    reservation_number: string;
    arrival: string;
    departure: string;
    adults: number;
    children: number;
    room_type_id: string;
    rate_plan_id: string;
    room_id: string | null;
};
const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';

export function AmendmentForm({
    reservation,
    options,
}: {
    reservation: Stay;
    options: AmendmentOptions;
}) {
    const [type, setType] = useState(reservation.room_type_id);
    return (
        <section className="max-w-[720px] space-y-3">
            <h2 className="text-lg font-semibold">Amend reservation</h2>
            <Form
                {...amend.form(reservation.reservation_number)}
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        <PropertyIntent />
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(
                                [
                                    'arrival',
                                    'departure',
                                    'adults',
                                    'children',
                                ] as const
                            ).map((field) => (
                                <div key={field} className="space-y-2">
                                    <Label htmlFor={`amend-${field}`}>
                                        {field[0].toUpperCase() +
                                            field.slice(1)}
                                    </Label>
                                    <Input
                                        id={`amend-${field}`}
                                        name={field}
                                        type={
                                            field === 'arrival' ||
                                            field === 'departure'
                                                ? 'date'
                                                : 'number'
                                        }
                                        defaultValue={
                                            typeof reservation[field] ===
                                            'string'
                                                ? String(
                                                      reservation[field],
                                                  ).slice(0, 10)
                                                : reservation[field]
                                        }
                                        required
                                        min={
                                            field === 'adults'
                                                ? 1
                                                : field === 'children'
                                                  ? 0
                                                  : undefined
                                        }
                                        aria-invalid={Boolean(errors[field])}
                                        aria-describedby={`amend-${field}-error`}
                                    />
                                    <div id={`amend-${field}-error`}>
                                        <InputError message={errors[field]} />
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="amend-type">Room type</Label>
                            <select
                                id="amend-type"
                                name="room_type_id"
                                value={type}
                                onChange={(event) =>
                                    setType(event.target.value)
                                }
                                className={selectClass}
                                aria-describedby="amend-type-error"
                            >
                                {options.roomTypes.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                            <div id="amend-type-error">
                                <InputError message={errors.room_type_id} />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="amend-plan">Rate plan</Label>
                            <select
                                key={type}
                                id="amend-plan"
                                name="rate_plan_id"
                                defaultValue={reservation.rate_plan_id}
                                className={selectClass}
                                aria-describedby="amend-plan-error"
                            >
                                {options.ratePlans
                                    .filter((plan) =>
                                        plan.room_types.some(
                                            (roomType) => roomType.id === type,
                                        ),
                                    )
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                            </select>
                            <div id="amend-plan-error">
                                <InputError message={errors.rate_plan_id} />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="amend-room">Room assignment</Label>
                            <select
                                key={type}
                                id="amend-room"
                                name="room_id"
                                defaultValue={
                                    type === reservation.room_type_id
                                        ? (reservation.room_id ?? '')
                                        : ''
                                }
                                className={selectClass}
                                aria-describedby="amend-room-error"
                            >
                                <option value="">Unassigned</option>
                                {options.rooms
                                    .filter(
                                        (room) => room.room_type_id === type,
                                    )
                                    .map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.number}
                                        </option>
                                    ))}
                            </select>
                            <div id="amend-room-error">
                                <InputError message={errors.room_id} />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Room availability and restrictions are checked
                                when saving. A refusal leaves the reservation
                                unchanged.
                            </p>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="amend-pricing">
                                Nightly prices
                            </Label>
                            <select
                                id="amend-pricing"
                                name="pricing"
                                defaultValue="keep"
                                className={selectClass}
                                aria-describedby="amend-pricing-error"
                            >
                                <option value="keep">
                                    Keep original prices; price added nights
                                    only
                                </option>
                                <option value="reprice">
                                    Re-price future nights
                                </option>
                            </select>
                            <div id="amend-pricing-error">
                                <InputError message={errors.pricing} />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="amend-reason">
                                Restriction override reason
                            </Label>
                            <Input
                                id="amend-reason"
                                name="restriction_override_reason"
                                maxLength={2000}
                                aria-describedby="amend-reason-error"
                            />
                            <div id="amend-reason-error">
                                <InputError
                                    message={errors.restriction_override_reason}
                                />
                            </div>
                        </div>
                        <InputError message={errors.reservation} />
                        <Button disabled={processing}>Save amendment</Button>
                    </>
                )}
            </Form>
        </section>
    );
}

export type Amendment = {
    id: string;
    occurred_at: string;
    actor: { name: string } | null;
    snapshot: {
        before: Record<string, unknown>;
        after: Record<string, unknown>;
        pricing: string;
    };
};

export function AmendmentHistory({
    amendments,
}: {
    amendments: Paginated<Amendment>;
}) {
    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold">Amendment history</h2>
            {amendments.data.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No amendments yet.
                </p>
            )}
            {amendments.data.map((amendment) => (
                <article
                    key={amendment.id}
                    className="space-y-2 rounded-md border p-4"
                >
                    <p>
                        {amendment.actor?.name ?? 'System'} ·{' '}
                        <time dateTime={amendment.occurred_at}>
                            {new Date(amendment.occurred_at).toLocaleString()}
                        </time>
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {amendment.snapshot.pricing === 'keep'
                            ? 'Original prices kept'
                            : 'Future nights re-priced'}
                    </p>
                    <details>
                        <summary className="cursor-pointer">
                            Before and after values
                        </summary>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['before', 'after'] as const).map((side) => (
                                <div key={side}>
                                    <h3 className="font-medium capitalize">
                                        {side}
                                    </h3>
                                    <pre className="overflow-auto text-xs break-all whitespace-pre-wrap">
                                        {JSON.stringify(
                                            amendment.snapshot[side],
                                            null,
                                            2,
                                        )}
                                    </pre>
                                </div>
                            ))}
                        </div>
                    </details>
                </article>
            ))}
            <Pagination page={amendments} />
        </section>
    );
}
