<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by the subscriptions:expire command the moment it actually
 * transitions a subscription from active to expired (or cancelled, if the
 * owner had requested cancellation) — never sent merely because a date
 * passed while nobody ran the command, and never sent twice for the same
 * subscription (the status change this depends on only ever happens once).
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
        $businessName = $this->subscription->business?->name ?? 'your business';

        return (new MailMessage)
            ->subject("Your {$planName} subscription has expired")
            ->greeting('Hello!')
            ->line("The {$planName} subscription for {$businessName} expired on ".$this->subscription->expires_at?->format('d M Y').'.')
            ->line('Paid plan features have now ended — your store remains, but plan-specific features are no longer available.')
            ->line('You can renew at any time to restore them.')
            ->action('Renew your subscription', route('subscription.edit'));
    }
}
