import { Form, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip } from '@/components/ui/status-chip';
import { goLive, taxesReviewed } from '@/routes/properties';

export type Checklist = {
    propertyId: string;
    propertyName: string;
    items: {
        key: string;
        label: string;
        completed: boolean;
        href: string | null;
    }[];
    gates: { key: string; label: string; passed: boolean }[];
    canGoLive: boolean;
    canReviewTaxes: boolean;
    receiptNotice: string | null;
};

export function OnboardingChecklist({ checklist }: { checklist: Checklist }) {
    const completed = checklist.items.filter((item) => item.completed).length;

    return (
        <div className="flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
            <header className="space-y-2">
                <h1 className="text-2xl font-semibold tracking-tight break-words">
                    Set up {checklist.propertyName}
                </h1>
                <p className="text-sm text-muted-foreground">
                    Your progress is saved automatically from your property
                    data. You can leave and return at any time.
                </p>
                <p className="text-sm tabular-nums">
                    {completed} of {checklist.items.length} completed
                </p>
            </header>

            {checklist.receiptNotice && (
                <Alert>
                    <AlertDescription>
                        {checklist.receiptNotice}
                    </AlertDescription>
                </Alert>
            )}

            <section aria-labelledby="setup-checklist">
                <h2 id="setup-checklist" className="mb-3 text-lg font-semibold">
                    Onboarding checklist
                </h2>
                <ul className="divide-y rounded-xl border px-4">
                    {checklist.items.map((item) => (
                        <li key={item.key} className="space-y-3 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                {item.href ? (
                                    <Link
                                        href={item.href}
                                        className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        {item.label}
                                    </Link>
                                ) : (
                                    <span className="text-sm font-medium">
                                        {item.label}
                                    </span>
                                )}
                                <StatusChip
                                    tone={
                                        item.completed ? 'success' : 'warning'
                                    }
                                >
                                    {item.completed ? 'Complete' : 'To do'}
                                </StatusChip>
                            </div>
                            {!item.href && (
                                <p className="text-sm text-muted-foreground">
                                    CSV import is not available yet. This step
                                    is optional for go-live.
                                </p>
                            )}
                            {item.key === 'taxes_reviewed' &&
                                !item.completed &&
                                checklist.canReviewTaxes && (
                                    <Form
                                        {...taxesReviewed.form(
                                            checklist.propertyId,
                                        )}
                                        disableWhileProcessing
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing, errors }) => (
                                            <div className="space-y-2">
                                                <PropertyIntent
                                                    propertyId={
                                                        checklist.propertyId
                                                    }
                                                />
                                                <p className="text-sm text-muted-foreground">
                                                    Open Review taxes first,
                                                    then confirm the rules are
                                                    correct for this property.
                                                </p>
                                                <InputError
                                                    message={errors._property}
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    {processing && <Spinner />}
                                                    Mark taxes reviewed
                                                </Button>
                                            </div>
                                        )}
                                    </Form>
                                )}
                        </li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby="go-live-gates" className="space-y-4">
                <div className="space-y-1">
                    <h2 id="go-live-gates" className="text-lg font-semibold">
                        Ready to go live?
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        These requirements must pass before guests can check in.
                        The other checklist steps are guidance, not blockers.
                    </p>
                </div>
                <ul className="space-y-3">
                    {checklist.gates.map((gate) => (
                        <li
                            key={gate.key}
                            className="flex flex-wrap items-center justify-between gap-3"
                        >
                            <span className="text-sm">{gate.label}</span>
                            <StatusChip
                                tone={gate.passed ? 'success' : 'warning'}
                            >
                                {gate.passed ? 'Passed' : 'Required'}
                            </StatusChip>
                        </li>
                    ))}
                </ul>
                {checklist.canGoLive && (
                    <Form
                        {...goLive.form(checklist.propertyId)}
                        disableWhileProcessing
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-3">
                                <PropertyIntent
                                    propertyId={checklist.propertyId}
                                />
                                <InputError message={errors._property} />
                                <InputError message={errors.go_live} />
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    Go live
                                </Button>
                            </div>
                        )}
                    </Form>
                )}
            </section>
        </div>
    );
}
