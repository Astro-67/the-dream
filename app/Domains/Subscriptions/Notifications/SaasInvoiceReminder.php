<?php

namespace App\Domains\Subscriptions\Notifications;

use App\Domains\Subscriptions\Models\SaasInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SaasInvoiceReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SaasInvoice $invoice, public readonly bool $overdue)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->overdue ? __('Your Karafuu invoice is overdue') : __('Your Karafuu invoice is due soon'))
            ->line(__('Invoice :number is due on :date.', ['number' => $this->invoice->number, 'date' => $this->invoice->due_at->toDateString()]))
            ->line(__('Review your subscription for the amount owed and payment status.'))
            ->action(__('View subscription'), route('subscription.show'));
    }
}
