<?php

use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\SaasPaymentSlip;
use App\Domains\Subscriptions\Models\Subscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    withBillingDetails();
    Storage::fake('local');
});

test('an owner attaches several private payment slips without paying the invoice', function () {
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));

    $this->actingAs($owner)->post(route('subscription.invoices.slips.store', $invoice), [
        'files' => [
            UploadedFile::fake()->create('transfer.pdf', 5120, 'application/pdf'),
            UploadedFile::fake()->image('receipt.jpg'),
            UploadedFile::fake()->image('receipt.png'),
        ],
        'reference' => 'BANK-123',
    ])->assertRedirect(route('subscription.invoices.show', $invoice))->assertSessionHasNoErrors();

    inTenant($organization, function () use ($invoice, $owner) {
        $slips = SaasPaymentSlip::query()->get();
        expect($slips)->toHaveCount(3);
        foreach ($slips as $slip) {
            Storage::disk('local')->assertExists($slip->path);
            expect($slip->saas_invoice_id)->toBe($invoice->id)
                ->and($slip->reference)->toBe('BANK-123')
                ->and($slip->uploaded_by)->toBe($owner->id);
        }
        expect($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Open)
            ->and($invoice->balance())->toBe($invoice->amount)
            ->and(SaasPayment::query()->count())->toBe(0)
            ->and(Subscription::current()->status)->toBe(SubscriptionStatus::Trial);
    });
});
