<?php

namespace App\Notifications;

use App\Models\PaymentTransaction;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a payment attempt (a new subscription, renewal, upgrade, or
 * downgrade) is verified as not successful. Never carries any credential
 * or raw Paystack payload — only the safe fields already on the
 * transaction. Never claims the payment succeeded.
 */
class PaymentFailed extends Notification
{
    public function __construct(private readonly PaymentTransaction $transaction) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->transaction->plan?->name ?? 'the selected plan';
        $businessName = $this->transaction->business?->name ?? 'your business';
        $billingPeriod = $this->transaction->subscription?->billing_period;

        $message = (new MailMessage)
            ->subject('Your payment could not be completed')
            ->greeting('Hello!')
            ->line("We couldn't confirm a payment for {$businessName}'s {$planName} plan.")
            ->line('Amount attempted: '.$this->transaction->formattedAmount());

        if ($billingPeriod) {
            $message->line('Billing period: '.ucfirst($billingPeriod));
        }

        return $message
            ->line('Reference: '.$this->transaction->reference)
            ->line('Your current plan and access are unaffected — nothing has been charged or changed.')
            ->action('Try the payment again', route('subscription.edit'));
    }
}
