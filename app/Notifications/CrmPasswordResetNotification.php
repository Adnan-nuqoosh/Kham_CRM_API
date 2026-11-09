<?php
namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class CrmPasswordResetNotification extends ResetPassword
{
    public function __construct(string $token, private readonly string $frontendUrl)
    {
        parent::__construct($token);
    }

    public function toMail($notifiable)
    {
        $separator = str_contains($this->frontendUrl, '?') ? '&' : '?';
        $url = $this->frontendUrl . $separator . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        return (new MailMessage)
            ->subject('Reset your KHAM CRM password')
            ->line('We received a request to reset your KHAM CRM password.')
            ->action('Reset Password', $url)
            ->line('This link expires in 60 minutes. If you did not request this, ignore this email.');
    }
}
