<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffAccountInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public string $inviteeName,
        public string $role,
        public string $acceptUrl,
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
            ->subject('You are invited to join PrintSync')
            ->greeting("Hello {$this->inviteeName},")
            ->line("An administrator invited you to join PrintSync as a {$this->role}.")
            ->line('Accept the invitation and choose a password to finish setting up your account.')
            ->action('Accept invitation', $this->acceptUrl)
            ->line('This link expires in seven days and can only be used once.');
    }
}
