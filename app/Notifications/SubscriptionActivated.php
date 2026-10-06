<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a business owner when a subscription period becomes active —
 * from a verified payment (new, renewed, upgraded) or a scheduled
 * downgrade being promoted. Sent synchronously via the application's
 * existing mail channel (MAIL_MAILER, currently "log" in local/testing —
 * see .env), so nothing new was introduced for this: no queue, no SMS, no
 * third-party notification service.
 */
class SubscriptionActivated extends Notification
{
    public function __construct(private readonly Subscription $subscription) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->subscription->plan?->name ?? 'your plan';

        return (new MailMessage)
            ->subject("Your {$planName} subscription is now active")
            ->greeting('Hello!')
            ->line("Your subscription to {$planName} is now active.")
            ->line('Billing period: '.ucfirst((string) $this->subscription->billing_period))
            ->line('Starts: '.$this->subscription->starts_at?->format('d M Y'))
            ->line('Expires: '.($this->subscription->expires_at?->format('d M Y') ?? 'Does not expire'));
    }
}
