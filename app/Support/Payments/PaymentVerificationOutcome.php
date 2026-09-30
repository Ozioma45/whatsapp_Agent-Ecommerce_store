<?php

namespace App\Support\Payments;

use App\Models\PaymentTransaction;

final readonly class PaymentVerificationOutcome
{
    public const REFERENCE_MISMATCH = 'reference_mismatch';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    public const NOT_OWNER = 'not_owner';

    public const VERIFICATION_UNAVAILABLE = 'verification_unavailable';

    public const ALREADY_PROCESSED = 'already_processed';

    private function __construct(
        public bool $successful,
        public string $status,
        public PaymentTransaction $transaction,
    ) {}

    public static function success(PaymentTransaction $transaction): self
    {
        return new self(true, PaymentTransaction::STATUS_SUCCESSFUL, $transaction);
    }

    /**
     * A transaction whose payment genuinely did not succeed (failed,
     * abandoned) — a legitimate, verified outcome, just not a paid one.
     */
    public static function unsuccessfulPayment(PaymentTransaction $transaction): self
    {
        return new self(false, $transaction->status, $transaction);
    }

    /**
     * A transaction that was already fully processed by an earlier
     * verify/webhook call — the idempotency guard, not a new outcome.
     */
    public static function alreadyProcessed(PaymentTransaction $transaction): self
    {
        return new self($transaction->status === PaymentTransaction::STATUS_SUCCESSFUL, self::ALREADY_PROCESSED, $transaction);
    }

    /**
     * A check this application itself enforces failed (reference,
     * amount, currency, ownership, or Paystack being unreachable) — the
     * transaction is marked failed except where noted.
     */
    public static function rejected(PaymentTransaction $transaction, string $status): self
    {
        return new self(false, $status, $transaction);
    }
}
