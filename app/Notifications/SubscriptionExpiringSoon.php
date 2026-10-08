<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once per subscription by the subscriptions:expire command when its
 * expires_at is within config('subscriptions.expiry_reminder_days') — see
 * Subscription::$expiry_reminder_sent_at, which the command sets right
 * after dispatching this, so a daily run never sends it twice. Only ever
 * sent for a subscription that is still status=active (never expired or
 * suspended — see ExpireSubscriptionsCommand's query).
 */
class SubscriptionExpiringSoon extends Notification
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
        $businessName = $this->subscription->business?->name ?? 'your business';
        $days = $this->subscription->daysRemaining();

        return (new MailMessage)
            ->subject("Your {$planName} subscription is expiring soon")
            ->greeting('Hello!')
            ->line("The {$planName} subscription for {$businessName} expires on ".$this->subscription->expires_at?->format('d M Y').($days !== null ? " ({$days} day(s) from now)." : '.'))
            ->line('Renew from your subscription page to keep your current plan features without interruption.')
            ->action('Renew your subscription', route('subscription.edit'));
    }
}
