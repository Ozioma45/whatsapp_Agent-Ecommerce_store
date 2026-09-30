<?php

namespace App\Support\Payments;

use App\Models\PaymentTransaction;

final readonly class PaymentInitiationResult
{
    private function __construct(
        public bool $successful,
        public ?string $authorizationUrl,
        public ?PaymentTransaction $transaction,
        public ?string $error,
    ) {}

    public static function success(string $authorizationUrl, PaymentTransaction $transaction): self
    {
        return new self(true, $authorizationUrl, $transaction, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
