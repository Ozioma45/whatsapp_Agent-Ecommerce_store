<?php

namespace App\Support\Payments;

/**
 * The outcome of asking Paystack to verify one transaction. `successful`
 * only means the API call itself succeeded — `paystackStatus` is what
 * actually says whether the payment succeeded ("success"), failed, or was
 * abandoned. Never trust a caller's own claim about payment status; this
 * is always built from Paystack's own verification response.
 */
final readonly class PaystackVerificationResult
{
    private function __construct(
        public bool $successful,
        public ?string $paystackStatus,
        public ?int $amount,
        public ?string $currency,
        public ?string $reference,
        public ?string $transactionId,
        public ?string $channel,
        public ?string $error,
    ) {}

    public static function success(
        string $paystackStatus,
        int $amount,
        string $currency,
        string $reference,
        string $transactionId,
        ?string $channel,
    ): self {
        return new self(true, $paystackStatus, $amount, $currency, $reference, $transactionId, $channel, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, null, null, null, null, null, $error);
    }
}
