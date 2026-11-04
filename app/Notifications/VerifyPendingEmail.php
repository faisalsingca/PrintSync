<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyPendingEmail extends Notification
{
    use Queueable;

    /**
     * Create a pending email verification notification.
     */
    public function __construct(
        public readonly string $userName,
        public readonly string $url,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your new PrintSync email address')
            ->greeting("Hello {$this->userName},")
            ->line('A request was made to use this address for a PrintSync account.')
            ->action('Verify new email address', $this->url)
            ->line('The email address will not be changed until the required confirmations are complete.')
            ->line('If you did not request this change, you can ignore this email.');
    }
}
