<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AttachPaymentSlips
{
    /** @param list<UploadedFile> $files */
    public function handle(User $actor, SaasInvoice $invoice, array $files, ?string $reference): void
    {
        $paths = [];

        try {
            Audit::validationRefusal($actor, $invoice, 'Payment slip upload refused', function () use ($actor, $invoice, $files, $reference, &$paths) {
                DB::transaction(function () use ($actor, $invoice, $files, $reference, &$paths) {
                    $invoice = SaasInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                    if ($invoice->status !== SaasInvoiceStatus::Open) {
                        throw ValidationException::withMessages(['files' => __('Payment slips can only be attached to an open invoice.')]);
                    }

                    foreach ($files as $file) {
                        $path = $file->store("payment-slips/{$invoice->organization_id}/{$invoice->id}", 'local');
                        if ($path === false) {
                            throw new RuntimeException('Payment slip could not be stored.');
                        }
                        $paths[] = $path;
                        $slip = $invoice->slips()->create([
                            'path' => $path, 'original_name' => $file->getClientOriginalName(),
                            'reference' => $reference, 'uploaded_by' => $actor->id,
                        ]);
                        Audit::record($actor, 'saas_payment_slip_uploaded', $slip, 'Payment slip uploaded', [
                            'attributes' => ['invoice' => $invoice->number],
                        ]);
                    }
                });
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paths);

            throw $exception;
        }
    }
}
