import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export function RestrictionFields({
    errors,
}: {
    errors: Record<string, string>;
}) {
    return (
        <>
            <div className="space-y-2">
                <Label htmlFor="min_stay">Minimum stay (nights)</Label>
                <Input
                    id="min_stay"
                    name="min_stay"
                    type="number"
                    min={1}
                    max={365}
                    step={1}
                    defaultValue={1}
                    required
                    className="max-w-40"
                    aria-invalid={!!errors.min_stay}
                />
                <InputError message={errors.min_stay} />
            </div>
            <div className="flex flex-wrap gap-6">
                {(
                    [
                        ['closed_to_arrival', 'Closed to arrival'],
                        ['stop_sell', 'Stop-sell'],
                    ] as const
                ).map(([name, text]) => (
                    <div key={name}>
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name={name}
                                value="1"
                                className="size-4 accent-primary"
                                aria-invalid={!!errors[name]}
                            />
                            {text}
                        </label>
                        <InputError message={errors[name]} />
                    </div>
                ))}
            </div>
        </>
    );
}
