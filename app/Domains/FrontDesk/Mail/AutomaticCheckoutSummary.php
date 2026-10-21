<?php

namespace App\Domains\FrontDesk\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AutomaticCheckoutSummary extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $checkedOut
     * @param  array<string, string>  $skipped
     */
    public function __construct(
        public string $propertyName,
        public string $date,
        public array $checkedOut,
        public array $skipped,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Automatic check-out summary: :property - :date', ['property' => $this->propertyName, 'date' => $this->date]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.automatic-checkout-summary');
    }
}
