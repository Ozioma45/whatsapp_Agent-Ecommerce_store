<?php

namespace App\Notifications;

use App\Models\PaymentTransaction;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a payment attempt (renewal, upgrade, or downgrade) is
 * verified as not successful. Never carries any credential or raw
 * Paystack payload — only the safe fields already on the transaction.
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

        return (new MailMessage)
            ->subject('Your payment could not be completed')
            ->greeting('Hello!')
            ->line("We couldn't confirm your payment for {$planName}.")
            ->line('Reference: '.$this->transaction->reference)
            ->line('Your current plan and access are unaffected. You can try again from your subscription page.');
    }
}
