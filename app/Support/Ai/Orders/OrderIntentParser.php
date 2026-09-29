<?php

namespace App\Support\Ai\Orders;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Deterministic, rule-based parsing of a customer's message for order
 * intent — no AI model is involved in any of this. Confirmation and
 * cancellation only ever match an exact (trimmed, case-insensitive)
 * phrase, deliberately excluding vague words like "okay" or "thanks", so
 * a customer can only confirm an order by clearly saying so.
 *
 * Known limitation: product matching is a simple substring match against
 * one product's name at a time, so a single message can only add one
 * product — a customer wanting several products sends one message per
 * product. This mirrors DeterministicFakeAiProvider's own matching and is
 * an accepted trade-off of not using a real language model here.
 */
class OrderIntentParser
{
    /**
     * @var array<int, string>
     */
    private const CONFIRM_PHRASES = ['confirm', 'confirm order', 'yes', 'yes confirm', 'place order', 'proceed'];

    /**
     * @var array<int, string>
     */
    private const CANCEL_PHRASES = ['cancel', 'cancel order', 'stop', 'no'];

    /**
     * @var array<int, string>
     */
    private const CLEAR_PHRASES = ['clear', 'clear order', 'start over', 'reset'];

    /**
     * @var array<int, string>
     */
    private const CHECKOUT_PHRASES = ['checkout', 'check out', 'done', "that's all", "i'm done", 'im done'];

    /**
     * @var array<int, string>
     */
    private const REVIEW_PHRASES = ['review order', 'review my order', 'show my order', 'my order', 'order summary', 'view order'];

    /**
     * @var array<int, string>
     */
    private const REMOVE_PREFIXES = ['remove ', 'delete ', 'take off '];

    public static function isConfirmation(string $text): bool
    {
        return in_array(self::normalize($text), self::CONFIRM_PHRASES, true);
    }

    public static function isCancellation(string $text): bool
    {
        return in_array(self::normalize($text), self::CANCEL_PHRASES, true);
    }

    public static function isClear(string $text): bool
    {
        return in_array(self::normalize($text), self::CLEAR_PHRASES, true);
    }

    public static function isCheckoutRequest(string $text): bool
    {
        return in_array(self::normalize($text), self::CHECKOUT_PHRASES, true);
    }

    public static function isReviewRequest(string $text): bool
    {
        return in_array(self::normalize($text), self::REVIEW_PHRASES, true);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    public static function removalTarget(string $text, Collection $products): ?Product
    {
        $normalized = self::normalize($text);

        foreach (self::REMOVE_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return self::findProduct(trim(substr($normalized, strlen($prefix))), $products);
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return ?array{product: Product, quantity: ?int}
     */
    public static function quantityAndProduct(string $text, Collection $products): ?array
    {
        $normalized = self::normalize($text);
        $product = self::findProduct($normalized, $products);

        if (! $product) {
            return null;
        }

        $quantity = null;

        if (preg_match('/(\d+)/', $normalized, $matches)) {
            $quantity = max(1, min(99, (int) $matches[1]));
        }

        return ['product' => $product, 'quantity' => $quantity];
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private static function findProduct(string $normalized, Collection $products): ?Product
    {
        foreach ($products as $product) {
            if (str_contains($normalized, mb_strtolower($product->name))) {
                return $product;
            }
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        return rtrim(mb_strtolower(trim(preg_replace('/\s+/', ' ', $text))), '.!?, ');
    }
}
