<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailAddressChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $userName,
        public readonly string $newEmail,
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
            ->subject('Security alert: your email address was changed')
            ->greeting("Hello {$this->userName},")
            ->line('The email address associated with your PrintSync account was changed.')
            ->line("Your new email address is {$this->newEmail}.")
            ->line('If you did not make this change, contact your administrator immediately.');
    }
}
