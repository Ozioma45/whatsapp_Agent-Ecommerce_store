<?php

namespace App\Support\Payments;

/**
 * The outcome of asking Paystack to initialize one transaction. Carries no
 * secret — only what's needed to redirect the browser to Paystack's
 * hosted checkout.
 */
final readonly class PaystackInitializationResult
{
    private function __construct(
        public bool $successful,
        public ?string $authorizationUrl,
        public ?string $accessCode,
        public ?string $error,
    ) {}

    public static function success(string $authorizationUrl, ?string $accessCode): self
    {
        return new self(true, $authorizationUrl, $accessCode, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
