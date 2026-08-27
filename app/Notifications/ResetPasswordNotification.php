<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $url)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset Password - JACOS')
            ->line('Kami menerima permintaan reset password untuk akun Anda.')
            ->action('Reset Password', $this->url)
            ->line('Link ini berlaku selama 60 menit. Jika Anda tidak meminta ini, abaikan email ini.');
    }
}
