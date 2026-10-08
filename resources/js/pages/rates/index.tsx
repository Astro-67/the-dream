import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { create } from '@/routes/rate-plans';

type Plan = {
    id: string;
    name: string;
    code: string;
    currency: string;
    roomTypes: { id: string; name: string }[];
};
export default function RatePlans({
    ratePlans,
    canManage,
}: {
    ratePlans: Plan[];
    canManage: boolean;
}) {
    return (
        <>
            <Head title="Rate plans" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Rate plans
                    </h1>
                    {canManage && (
                        <Button asChild>
                            <Link href={create()}>Add rate plan</Link>
                        </Button>
                    )}
                </header>
                {ratePlans.length === 0 ? (
                    <p className="text-muted-foreground">
                        No rate plans yet. Add a rate plan to set prices.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="p-3">Code</th>
                                    <th className="p-3">Name</th>
                                    <th className="p-3">Currency</th>
                                    <th className="p-3">Room types</th>
                                </tr>
                            </thead>
                            <tbody>
                                {ratePlans.map((plan) => (
                                    <tr
                                        key={plan.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 font-mono">
                                            {plan.code}
                                        </td>
                                        <td className="p-3">{plan.name}</td>
                                        <td className="p-3">{plan.currency}</td>
                                        <td className="p-3">
                                            {plan.roomTypes
                                                .map((room) => room.name)
                                                .join(', ')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
