import { Head } from '@inertiajs/react';
import { Identifier } from '@/components/ui/identifier';
import { StatusChip } from '@/components/ui/status-chip';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { formatMoney } from '@/lib/utils';

type Named = { id: string; name: string };
type Reservation = { reservation_number: string; status: string; arrival: string; departure: string; adults: number; children: number; source: string; payment_collector: string; price_lines: { id: string; date: string; amount: number; currency: string }[] };

export default function Show({ reservation, roomType, ratePlan, booker }: { reservation: Reservation; roomType: Named; ratePlan: Named; booker: Named }) {
    return <><Head title={`Reservation ${reservation.reservation_number}`} /><div className="flex flex-col gap-6 p-4 md:p-6">
        <header className="space-y-3"><h1 className="text-2xl font-semibold">Reservation <Identifier value={reservation.reservation_number} copyable /></h1><StatusChip tone="success">{reservation.status}</StatusChip></header>
        <dl className="grid gap-4 sm:grid-cols-2">{[
            ['Room type', roomType.name], ['Rate plan', ratePlan.name], ['Arrival', formatDate(reservation.arrival, 'UTC')], ['Departure', formatDate(reservation.departure, 'UTC')], ['Guests', `${reservation.adults} adults, ${reservation.children} children`], ['Booker', booker.name], ['Source', reservation.source], ['Payment collector', reservation.payment_collector],
        ].map(([label, value]) => <div key={label}><dt className="text-sm text-muted-foreground">{label}</dt><dd>{value}</dd></div>)}</dl>
        <section className="space-y-3"><h2 className="text-lg font-semibold">Fixed nightly prices</h2><Table><TableHeader><TableRow><TableHead>Night</TableHead><TableHead numeric>Amount</TableHead></TableRow></TableHeader><TableBody>{reservation.price_lines.map(line => <TableRow key={line.id}><TableCell>{formatDate(line.date, 'UTC')}</TableCell><TableCell numeric>{formatMoney(line.amount, line.currency)} {line.currency}</TableCell></TableRow>)}</TableBody></Table></section>
    </div></>;
}
