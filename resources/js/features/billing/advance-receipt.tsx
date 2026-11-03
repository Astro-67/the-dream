import { Form, Link, router } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PropertyIntent } from '@/components/shared/property-intent';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/select';
import { StatusChip } from '@/components/ui/status-chip';
import { Spinner } from '@/components/ui/spinner';

export type AdvancePayment = {
    status: string;
    reason?: string | null;
    company?: string | null;
    fileUrl?: string | null;
    reservationUrl?: string;
    bookingNumber?: string | null;
    receiptNumber?: string | null;
    receiptUrl?: string | null;
    qrSvg?: string | null;
    mode?: string;
};

export function AdvanceReceipt({ advance }: { advance: AdvancePayment }) {
    const accepted = advance.status === 'delivered';
    const sandbox = advance.mode === 'sandbox';
    const label = accepted
        ? sandbox
            ? 'Sandbox - not fiscal'
            : 'Accepted by ZRA'
        : advance.status === 'unknown' || advance.status === 'exhausted_unknown'
          ? 'Unknown - do not file again'
          : advance.status === 'rejected'
            ? 'Refused by ZRA'
            : advance.status === 'not_filed' || advance.status === 'unavailable'
              ? 'Not filed'
              : 'Waiting for ZRA';
    return (
        <section className="space-y-4 rounded-xl border bg-card p-4 print:hidden">
            <div className="flex flex-wrap items-center gap-3">
                <h2 className="font-semibold">ZRA advance payment</h2>
                <StatusChip
                    tone={
                        accepted && !sandbox
                            ? 'success'
                            : advance.status === 'rejected'
                              ? 'error'
                              : 'warning'
                    }
                >
                    {label}
                </StatusChip>
            </div>
            {advance.reason && (
                <p className="text-sm text-muted-foreground">
                    {advance.reason}
                </p>
            )}
            {accepted && (
                <>
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Booking</dt>
                            <dd>
                                <Identifier
                                    value={advance.bookingNumber ?? ''}
                                    copyable
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Advance receipt
                            </dt>
                            <dd>
                                <Identifier
                                    value={advance.receiptNumber ?? ''}
                                    copyable
                                />
                            </dd>
                        </div>
                    </dl>
                    <p className="text-sm text-muted-foreground">
                        {sandbox ? 'This test receipt is not fiscal. ' : ''}
                        This booking and receipt will be linked at check-in. The
                        original payment receipt stays non-fiscal and unchanged.
                    </p>
                    {advance.receiptUrl && (
                        <a
                            href={advance.receiptUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex flex-col items-center gap-2 text-sm underline underline-offset-4"
                        >
                            {advance.qrSvg && (
                                <span
                                    className="size-28 bg-qr-surface p-2 [&_svg]:size-full"
                                    aria-hidden="true"
                                    dangerouslySetInnerHTML={{
                                        __html: advance.qrSvg,
                                    }}
                                />
                            )}
                            View ZRA advance receipt
                        </a>
                    )}
                </>
            )}
            {advance.fileUrl && (
                <details>
                    <summary className="cursor-pointer text-sm underline underline-offset-4">
                        File a tour-operator advance receipt
                    </summary>
                    <p className="mt-3 text-sm text-muted-foreground">
                        File this payment for {advance.company}. Only one
                        advance filing per reservation is supported. Afterwards,
                        reservation changes and payment corrections require ZRA
                        reconciliation; automatic advance refunds and
                        cancellation are not supported.
                    </p>
                    <Form
                        action={advance.fileUrl}
                        method="post"
                        className="mt-4 space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <PropertyIntent />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="advance-email">
                                            Tour operator email
                                        </Label>
                                        <Input
                                            id="advance-email"
                                            name="email"
                                            type="email"
                                            required
                                            maxLength={255}
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="advance-phone">
                                            Tour operator phone
                                        </Label>
                                        <Input
                                            id="advance-phone"
                                            name="phone"
                                            type="tel"
                                            required
                                            maxLength={50}
                                        />
                                        <InputError message={errors.phone} />
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="advance-zra-registration">
                                        Tour operator ZRA registration
                                    </Label>
                                    <NativeSelect
                                        id="advance-zra-registration"
                                        name="is_zra_registered"
                                        required
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Choose registration status
                                        </option>
                                        <option value="1">
                                            Registered with ZRA
                                        </option>
                                        <option value="0">
                                            Not registered with ZRA
                                        </option>
                                    </NativeSelect>
                                    <InputError
                                        message={errors.is_zra_registered}
                                    />
                                </div>
                                <InputError
                                    message={
                                        errors.vfms ??
                                        errors.posting ??
                                        errors.reservation
                                    }
                                />
                                <Button type="submit" disabled={processing}>
                                    <span className="size-4">
                                        {processing && <Spinner />}
                                    </span>
                                    File advance receipt with ZRA
                                </Button>
                            </>
                        )}
                    </Form>
                </details>
            )}
            {!accepted &&
                advance.status !== 'not_filed' &&
                advance.status !== 'unavailable' && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            router.reload({ only: ['advancePayment'] })
                        }
                    >
                        Refresh ZRA status
                    </Button>
                )}
            {advance.reservationUrl && (
                <Link
                    href={advance.reservationUrl}
                    className="inline-block text-sm underline underline-offset-4"
                >
                    View reservation and ZRA filing status
                </Link>
            )}
        </section>
    );
}
