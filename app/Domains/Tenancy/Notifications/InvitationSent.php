<?php

namespace App\Domains\Tenancy\Notifications;

use App\Domains\Tenancy\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email that carries an invitation link. The link holds the secret token,
 * which exists only here and in the recipient's inbox.
 */
class InvitationSent extends Notification
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly Invitation $invitation,
        private readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return route('invitations.show', ['organization' => $this->invitation->organization_id, 'token' => $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to join {$this->organizationName} on ".config('app.name'))
            ->line("{$this->inviterName} has invited you to join {$this->organizationName} as {$this->invitation->role->label()}.")
            ->action('Accept invitation', $this->url())
            ->line("Sign in or register with {$this->invitation->email} to accept. The invitation expires on {$this->invitation->expires_at->format('j M Y')}.");
    }
}
