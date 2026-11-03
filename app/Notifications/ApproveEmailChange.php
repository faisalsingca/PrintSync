<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApproveEmailChange extends Notification
{
    use Queueable;

    /**
     * Create an email-change approval notification.
     */
    public function __construct(
        public readonly string $userName,
        public readonly string $newEmail,
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
            ->subject('Approve your PrintSync email change')
            ->greeting("Hello {$this->userName},")
            ->line("A request was made to change your PrintSync email address to {$this->newEmail}.")
            ->line('Your current email address will stay in use until you approve this request and verify the new address.')
            ->line('This approval link expires in one hour.')
            ->action('Approve email change', $this->url)
            ->line('If you did not request this change, you can ignore this email.');
    }
}
