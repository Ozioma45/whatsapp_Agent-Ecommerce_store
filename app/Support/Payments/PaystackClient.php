<?php

namespace App\Support\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place in the app that talks to Paystack's API. Fails safely
 * (never throws, never makes the request) when the secret key isn't
 * configured, and never logs the key, the authorization header, or any
 * raw response body — only safe, structured outcome tags.
 */
class PaystackClient
{
    /**
     * Start a transaction. The email is required by Paystack's API and
     * identifies the payer on their side; nothing else about the business
     * is ever sent.
     */
    public function initializeTransaction(string $reference, int $amountInKobo, string $email, string $callbackUrl): PaystackInitializationResult
    {
        $secret = config('services.paystack.secret_key');

        if (blank($secret)) {
            Log::warning('paystack.initialize.not_configured');

            return PaystackInitializationResult::failure('Paystack is not configured.');
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(15)
                ->connectTimeout(5)
                ->post(rtrim((string) config('services.paystack.payment_url'), '/').'/transaction/initialize', [
                    'reference' => $reference,
                    'amount' => $amountInKobo,
                    'email' => $email,
                    'currency' => 'NGN',
                    'callback_url' => $callbackUrl,
                ]);
        } catch (ConnectionException) {
            Log::warning('paystack.initialize.network_error', ['reference' => $reference]);

            return PaystackInitializationResult::failure('Could not reach Paystack. Please try again.');
        }

        if (! $response->successful() || ! $response->json('status')) {
            Log::warning('paystack.initialize.failed', ['reference' => $reference, 'http_status' => $response->status()]);

            return PaystackInitializationResult::failure('Paystack could not start this payment. Please try again.');
        }

        $authorizationUrl = $response->json('data.authorization_url');

        if (! is_string($authorizationUrl) || $authorizationUrl === '') {
            Log::warning('paystack.initialize.missing_checkout_url', ['reference' => $reference]);

            return PaystackInitializationResult::failure('Paystack did not return a checkout link.');
        }

        return PaystackInitializationResult::success($authorizationUrl, $response->json('data.access_code'));
    }

    /**
     * Verify a transaction directly with Paystack — the only source of
     * truth for whether a payment actually succeeded.
     */
    public function verifyTransaction(string $reference): PaystackVerificationResult
    {
        $secret = config('services.paystack.secret_key');

        if (blank($secret)) {
            Log::warning('paystack.verify.not_configured', ['reference' => $reference]);

            return PaystackVerificationResult::failure('Paystack is not configured.');
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(15)
                ->connectTimeout(5)
                ->get(rtrim((string) config('services.paystack.payment_url'), '/')."/transaction/verify/{$reference}");
        } catch (ConnectionException) {
            Log::warning('paystack.verify.network_error', ['reference' => $reference]);

            return PaystackVerificationResult::failure('Could not reach Paystack to verify this payment.');
        }

        if (! $response->successful() || ! $response->json('status')) {
            Log::warning('paystack.verify.failed', ['reference' => $reference, 'http_status' => $response->status()]);

            return PaystackVerificationResult::failure('Paystack could not verify this payment.');
        }

        $data = (array) $response->json('data', []);

        return PaystackVerificationResult::success(
            paystackStatus: (string) ($data['status'] ?? 'unknown'),
            amount: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            reference: (string) ($data['reference'] ?? ''),
            transactionId: (string) ($data['id'] ?? ''),
            channel: $data['channel'] ?? null,
        );
    }
}
