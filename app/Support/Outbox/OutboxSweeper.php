<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class OutboxSweeper
{
    public function sweep(): int
    {
        $count = 0;
        $rows = Tenancy::within(TenantContext::platform('scheduled outbox recovery sweep'), fn () => DB::table('outbox_messages')
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'retryable')->where(function ($query): void {
                            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                        })->orWhere(function ($query): void {
                            $query->where('status', 'unknown')->where(function ($query): void {
                                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                            });
                        });
                    })
                    ->orWhere(function ($query): void {
                        $query->where('status', 'sending')->where('claim_expires_at', '<=', now());
                    });
            })
            ->orderBy('created_at')
            ->limit(500)
            ->get(['id', 'organization_id', 'property_id']));
        if ($rows->isEmpty()) {
            return 0;
        }

        foreach ($rows as $row) {
            $context = TenantContext::fromIds($row->organization_id, $row->property_id);
            Tenancy::within($context, function () use ($row, &$count): void {
                $message = OutboxMessage::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($message === null) {
                    return;
                }
                $due = $message->status === 'pending'
                    || ($message->status === 'retryable' && ($message->next_attempt_at === null || $message->next_attempt_at->isPast()))
                    || ($message->status === 'sending' && $message->claim_expires_at?->isPast());

                if ($message->status === 'unknown') {
                    $policy = app(DeliveryAdapters::class)->for($message->integration)->policy();
                    $due = $policy->unknownRetrySafe
                        && ($message->next_attempt_at === null || $message->next_attempt_at->isPast());
                }
                if (! $due) {
                    return;
                }
                if ($message->status === 'sending') {
                    $attempt = OutboxAttempt::query()
                        ->where('outbox_message_id', $message->id)
                        ->where('attempt_number', $message->attempt_count)
                        ->first();
                    if ($attempt !== null && $attempt->outcome === 'sending') {
                        $attempt->outcome = 'unknown';
                        $attempt->save();
                    }
                    $message->status = 'unknown';
                    $message->claim_token = null;
                    $message->claim_expires_at = null;
                    $message->save();

                    return;
                }

                $messageId = $message->id;
                $organizationId = $message->organization_id;
                $propertyId = $message->property_id;
                if ($message->status === 'retryable' || $message->status === 'unknown') {
                    $message->setAttribute('next_attempt_at', null);
                    if ($message->status === 'unknown') {
                        $message->status = 'pending';
                    }
                    $message->save();
                } else {
                    $message->save();
                }
                Tenancy::within(TenantContext::fromIds($organizationId, $propertyId), function () use ($messageId): void {
                    dispatch(new DrainOutboxMessage($messageId));
                });
                $count++;
            });
        }

        return $count;
    }
}
