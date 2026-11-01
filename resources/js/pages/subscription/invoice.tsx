import { Head, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import AppLayout from '@/layouts/app-layout';
import { index as platformInvoices } from '@/routes/platform/invoices';
import { show as subscription } from '@/routes/subscription';
import { Button } from '@/components/ui/button';
import { Identifier } from '@/components/ui/identifier';
import { Pagination } from '@/components/ui/pagination';
import type { BillingAccount, Paginated } from '@/types';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/features/platform/format';
import { PaymentSlips } from '@/features/subscriptions/payment-slips';
import type { PaymentSlip } from '@/features/subscriptions/payment-slips';
import { SaasInvoiceStatus } from '@/features/subscriptions/saas-invoice-status';
import type { SaasInvoice } from '@/features/subscriptions/saas-invoice-status';
import { formatMoney } from '@/lib/utils';

function InvoiceLayout({ children }: PropsWithChildren) {
    const platform = usePage().url.startsWith('/platform/invoices/');

    return (
        <AppLayout
            breadcrumbs={[
                platform
                    ? { title: 'SaaS invoices', href: platformInvoices() }
                    : { title: 'Subscription', href: subscription() },
                { title: 'Invoice', href: '#' },
            ]}
        >
            {children}
        </AppLayout>
    );
}

type Billing = {
    business_name: string;
    address: string;
    phone: string;
    email: string;
    tin: string;
    vrn: string | null;
    organization_name: string;
    accounts: BillingAccount[];
};

export default function SaasInvoiceShow({
    invoice,
    billing,
    payments,
    slips,
    uploadSlipsUrl,
}: {
    invoice: SaasInvoice & { plan: string; issuedAt: string | null };
    billing: Billing | null;
    slips: Paginated<PaymentSlip>;
    uploadSlipsUrl: string | null;
    payments: Paginated<{
        id: string;
        method: string;
        amount: number;
        reference: string;
        receivedOn: string;
    }>;
}) {
    return (
        <>
            <Head title={`Invoice ${invoice.number}`}>
                <style media="print">
                    {'@page { size: A4; margin: 12mm; }'}
                </style>
            </Head>
            <div className="receipt-print flex max-w-[720px] flex-col gap-6 p-4 md:p-6">
                <header className="space-y-3">
                    {billing && (
                        <div className="space-y-1 text-sm">
                            <p className="font-medium">
                                {billing.business_name}
                            </p>
                            <p className="text-muted-foreground">
                                {billing.address}. {billing.phone},{' '}
                                {billing.email}
                            </p>
                            <p className="text-muted-foreground">
                                TIN {billing.tin}
                                {billing.vrn && <>, VRN {billing.vrn}</>}
                            </p>
                        </div>
                    )}
                    <h1 className="text-2xl font-semibold">INVOICE</h1>
                    <div className="flex flex-wrap items-center gap-3">
                        <Identifier value={invoice.number} copyable />
                        <SaasInvoiceStatus invoice={invoice} />
                    </div>
                </header>

                <dl className="grid gap-4 sm:grid-cols-2">
                    {(
                        [
                            billing && ['Billed to', billing.organization_name],
                            ['Plan', invoice.plan],
                            [
                                'Period',
                                `${formatDate(invoice.periodStartsAt)} to ${formatDate(invoice.periodEndsAt)}`,
                            ],
                            ['Issued', formatDate(invoice.issuedAt)],
                            ['Due', formatDate(invoice.dueAt)],
                            [
                                'Amount',
                                invoice.rooms !== null &&
                                invoice.unitPrice !== null
                                    ? `${invoice.rooms} rooms × ${formatMoney(invoice.unitPrice, invoice.currency)} = ${formatMoney(invoice.amount, invoice.currency)}`
                                    : formatMoney(
                                          invoice.amount,
                                          invoice.currency,
                                      ),
                            ],
                            [
                                'Balance',
                                formatMoney(invoice.balance, invoice.currency),
                            ],
                        ] as const
                    )
                        .filter((row) => row !== null)
                        .map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-sm text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="tabular-nums">{value}</dd>
                            </div>
                        ))}
                </dl>

                {billing && (
                    <section aria-labelledby="pay" className="space-y-3">
                        <h2 id="pay" className="text-lg font-semibold">
                            How to pay
                        </h2>
                        <p className="text-sm">
                            Use the invoice number as the payment reference.
                        </p>
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {billing.accounts.map((account) => (
                                <li
                                    key={`${account.type}-${account.account_number}`}
                                    className="space-y-1 rounded-md border p-3 text-sm"
                                >
                                    {account.type === 'bank' ? (
                                        <>
                                            <p className="font-medium">
                                                {account.bank}, {account.branch}
                                            </p>
                                            {account.swift && (
                                                <p>
                                                    SWIFT{' '}
                                                    <Identifier
                                                        value={account.swift}
                                                    />
                                                </p>
                                            )}
                                        </>
                                    ) : (
                                        <p className="font-medium">
                                            {account.provider}
                                        </p>
                                    )}
                                    <p>{account.account_name}</p>
                                    <p>
                                        <Identifier
                                            value={account.account_number}
                                            copyable
                                        />
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {payments.total > 0 && (
                    <section aria-labelledby="payments" className="space-y-3">
                        <h2 id="payments" className="text-lg font-semibold">
                            Payments
                        </h2>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Received</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead>Reference</TableHead>
                                    <TableHead numeric>Amount</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payments.data.map((payment) => (
                                    <TableRow key={payment.id}>
                                        <TableCell>
                                            {formatDate(payment.receivedOn)}
                                        </TableCell>
                                        <TableCell>{payment.method}</TableCell>
                                        <TableCell>
                                            <Identifier
                                                value={payment.reference}
                                            />
                                        </TableCell>
                                        <TableCell numeric>
                                            {formatMoney(
                                                payment.amount,
                                                invoice.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={payments} className="print:hidden" />
                    </section>
                )}

                <section
                    aria-labelledby="payment-slips-heading"
                    className="space-y-3 print:hidden"
                >
                    <h2
                        id="payment-slips-heading"
                        className="text-lg font-semibold"
                    >
                        Payment slips
                    </h2>
                    <PaymentSlips
                        slips={slips.data}
                        uploadUrl={uploadSlipsUrl}
                    />
                    <Pagination page={slips} />
                </section>

                <div className="print:hidden">
                    <Button variant="outline" onClick={() => window.print()}>
                        Print
                    </Button>
                </div>
            </div>
        </>
    );
}

SaasInvoiceShow.layout = InvoiceLayout;
