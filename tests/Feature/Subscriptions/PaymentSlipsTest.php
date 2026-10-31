<?php

use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Enums\SubscriptionStatus;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\SaasPaymentSlip;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    withBillingDetails();
    Storage::fake('local');
});

test('only the invoice organization owners and platform administrators can download its slip', function () {
    $tenants = twoTenants();
    $invoice = inTenant($tenants->a->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $file = UploadedFile::fake()->image('payment.png');
    $contents = $file->get();
    $this->actingAs($tenants->a->owner)->post(route('subscription.invoices.slips.store', $invoice), [
        'files' => [$file],
    ])->assertSessionHasNoErrors();
    $slip = inTenant($tenants->a->organization, fn () => SaasPaymentSlip::query()->sole());

    $this->get(route('subscription.slips.show', $slip))->assertOk()->assertDownload('payment.png')
        ->assertStreamedContent($contents);
    foreach ($tenants->a->members() as $role => $member) {
        if ($role !== OrganizationRole::Owner->value) {
            $this->actingAs($member)->get(route('subscription.slips.show', $slip))->assertForbidden();
            $this->get(route('platform.slips.show', $slip->id))->assertForbidden();
        }
    }
    $this->actingAs($tenants->b->owner)->get(route('subscription.slips.show', $slip))->assertNotFound();
    $this->get(route('platform.slips.show', $slip->id))->assertForbidden();
    $this->actingAs(platformStaff())->get(route('platform.slips.show', $slip->id))
        ->assertOk()->assertDownload('payment.png')->assertStreamedContent($contents);
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

test('owners and administrators see slips beside the invoice without exposing storage paths', function () {
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $this->actingAs($owner)->post(route('subscription.invoices.slips.store', $invoice), [
        'files' => [UploadedFile::fake()->image('bank.png')], 'reference' => 'BANK-456',
    ])->assertSessionHasNoErrors();
    $slip = inTenant($organization, fn () => SaasPaymentSlip::query()->sole());

    $this->get(route('subscription.invoices.show', $invoice))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('slips.data', 1)
            ->where('slips.data.0.name', 'bank.png')->where('slips.data.0.reference', 'BANK-456')
            ->where('slips.data.0.url', route('subscription.slips.show', $slip))
            ->where('uploadSlipsUrl', route('subscription.invoices.slips.store', $invoice)))
        ->assertDontSee($slip->path);
    $this->actingAs(platformStaff())->get(route('platform.organizations.show', $organization))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data.0.slips.data', 1)
            ->where('invoices.data.0.slips.data.0.name', 'bank.png')
            ->where('invoices.data.0.slips.data.0.reference', 'BANK-456')
            ->where('invoices.data.0.slips.data.0.url', route('platform.slips.show', $slip->id)))
        ->assertDontSee($slip->path);
    $this->get(route('platform.invoices.show', $invoice->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('uploadSlipsUrl', null)
            ->where('slips.data.0.url', route('platform.slips.show', $slip->id)));
});

test('invalid slip uploads are refused as a whole without storing files', function (string $name, int $size, string $mime) {
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));

    $this->actingAs($owner)->post(route('subscription.invoices.slips.store', $invoice), [
        'files' => [UploadedFile::fake()->image('valid.png'), UploadedFile::fake()->create($name, $size, $mime)],
    ])->assertSessionHasErrors('files.1');

    expect(inTenant($organization, fn () => SaasPaymentSlip::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $invoice->id)->exists())->toBeTrue();
})->with([
    'oversized PDF' => ['large.pdf', 5121, 'application/pdf'],
    'oversized JPG' => ['large.jpg', 5121, 'image/jpeg'],
    'oversized PNG' => ['large.png', 5121, 'image/png'],
    'GIF' => ['image.gif', 10, 'image/gif'],
    'text' => ['notes.txt', 10, 'text/plain'],
    'executable extension' => ['receipt.php', 10, 'image/png'],
    'disguised content' => ['receipt.jpg', 10, 'text/plain'],
]);

test('uploads need at least one file and a bounded optional reference', function (array $payload, string $error) {
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $this->actingAs($owner)->post(route('subscription.invoices.slips.store', $invoice), $payload)
        ->assertSessionHasErrors($error);

    expect(inTenant($organization, fn () => SaasPaymentSlip::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'missing files' => [[], 'files'],
    'empty files' => [['files' => []], 'files'],
    'non-file value' => [['files' => ['not a file']], 'files.0'],
    'long reference' => [fn () => ['files' => [UploadedFile::fake()->image('valid.png')], 'reference' => str_repeat('x', 256)], 'reference'],
]);

test('a paid invoice refuses more slips and no longer offers the upload form', function () {
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $this->actingAs(platformStaff())->post(route('platform.invoices.payments.store', $invoice->id), [
        'amount' => $invoice->amount, 'method' => 'bank_transfer', 'reference' => 'PAID', 'received_on' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($owner)->post(route('subscription.invoices.slips.store', $invoice), [
        'files' => [UploadedFile::fake()->image('late.png')],
    ])->assertSessionHasErrors('files');
    $this->get(route('subscription.invoices.show', $invoice))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('uploadSlipsUrl', null)->where('invoice.status', 'paid'));
    expect(inTenant($organization, fn () => SaasPaymentSlip::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $invoice->id)->exists())->toBeTrue();
});

test('non-owner members cannot attach slips', function () {
    $tenants = twoTenants();
    $invoice = inTenant($tenants->a->organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    foreach ($tenants->a->members() as $role => $member) {
        if ($role !== OrganizationRole::Owner->value) {
            $this->actingAs($member)->post(route('subscription.invoices.slips.store', $invoice), [
                'files' => [UploadedFile::fake()->image('forbidden.png')],
            ])->assertForbidden();
        }
    }
    expect(inTenant($tenants->a->organization, fn () => SaasPaymentSlip::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('a missing stored slip returns not found rather than a broken download', function () {
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $this->actingAs($owner)->post(route('subscription.invoices.slips.store', $invoice), [
        'files' => [UploadedFile::fake()->image('missing.png')],
    ])->assertSessionHasNoErrors();
    $slip = inTenant($organization, fn () => SaasPaymentSlip::query()->sole());
    Storage::disk('local')->delete($slip->path);

    $this->get(route('subscription.slips.show', $slip))->assertNotFound();
    $this->actingAs(platformStaff())->get(route('platform.slips.show', $slip->id))->assertNotFound();
});

test('a storage failure rolls back the batch and removes files already uploaded', function () {
    [$owner, $organization] = organizationWithOwner();
    $invoice = inTenant($organization, fn () => app(IssueSaasInvoice::class)->handle(Subscription::current()));
    $disk = Storage::disk('local');
    $failingDisk = Mockery::mock($disk);
    $writes = 0;
    $failingDisk->shouldReceive('putFileAs')->andReturnUsing(function ($path, $file, $name, $options) use ($disk, &$writes) {
        return ++$writes === 1 ? $disk->putFileAs($path, $file, $name, $options) : false;
    });
    Storage::set('local', $failingDisk);

    $this->actingAs($owner)->post(route('subscription.invoices.slips.store', $invoice), [
        'files' => [UploadedFile::fake()->image('first.png'), UploadedFile::fake()->image('second.png')],
    ])->assertServerError();

    expect(inTenant($organization, fn () => SaasPaymentSlip::query()->count()))->toBe(0)
        ->and($disk->allFiles())->toBe([])
        ->and($invoice->fresh()->status)->toBe(SaasInvoiceStatus::Open)
        ->and(Activity::query()->where('event', 'saas_payment_slip_uploaded')->count())->toBe(0);
});
