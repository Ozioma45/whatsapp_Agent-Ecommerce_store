<?php

namespace App\Notifications;

use App\Models\PaymentTransaction;
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
        $businessName = $this->subscription->business?->name ?? 'your business';

        // The payment that actually activated this period, if any (an
        // admin-assigned subscription has none) — never a raw payload,
        // just the already-safe amount already stored on the transaction.
        $payment = $this->subscription->paymentTransactions()
            ->where('status', PaymentTransaction::STATUS_SUCCESSFUL)
            ->latest()
            ->first();

        $message = (new MailMessage)
            ->subject("Your {$planName} subscription is now active")
            ->greeting('Hello!')
            ->line("The subscription for {$businessName} to {$planName} is now active.")
            ->line('Billing period: '.ucfirst((string) $this->subscription->billing_period));

        if ($payment) {
            $message->line('Amount paid: '.$payment->formattedAmount());
        }

        return $message
            ->line('Start date: '.$this->subscription->starts_at?->format('d M Y'))
            ->line('Expiry date: '.($this->subscription->expires_at?->format('d M Y') ?? 'Does not expire'))
            ->action('View your subscription', route('subscription.edit'));
    }
}
