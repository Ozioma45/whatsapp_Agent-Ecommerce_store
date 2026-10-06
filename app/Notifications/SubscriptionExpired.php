<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by the subscriptions:expire command the moment it actually
 * transitions a subscription from active to expired (or cancelled, if the
 * owner had requested cancellation) — never sent merely because a date
 * passed while nobody ran the command.
 */
class SubscriptionExpired extends Notification
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
        $planName = $this->subscription->plan?->name ?? 'Your plan';

        return (new MailMessage)
            ->subject("Your {$planName} subscription has expired")
            ->greeting('Hello!')
            ->line("Your {$planName} subscription expired on ".$this->subscription->expires_at?->format('d M Y').'.')
            ->line('Paid plan features are no longer available. Renew any time from your subscription page.');
    }
}
