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
 * A product's name appearing in a message is never, on its own, enough to
 * add it to a draft — see isAvailabilityOrInfoInquiry() and
 * hasPurchaseIntent(). A question ("do you have...", "how much is...",
 * a message ending in "?") is always treated as an inquiry, never a
 * purchase, regardless of anything else in it; only a message that both
 * mentions a real product AND carries an explicit purchase phrase can
 * change a draft.
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

    /**
     * A message starting with (or containing) any of these is a question,
     * never a purchase instruction — checked before anything else.
     *
     * @var array<int, string>
     */
    private const INQUIRY_LEADING_WORDS = ['do ', 'does ', 'is ', 'are ', 'how ', 'what ', "what's", 'can ', 'could '];

    /**
     * @var array<int, string>
     */
    private const INQUIRY_PHRASES = [
        'do you have', 'do you sell', 'is available', 'is it available', 'in stock',
        'how much', "what's the price", 'what is the price', 'price of', 'can i see', 'can you show',
    ];

    /**
     * A message must contain one of these to ever add to or increase a
     * draft — a bare product name, or a product name inside a question, is
     * never enough on its own.
     *
     * @var array<int, string>
     */
    private const PURCHASE_LEADING_PHRASES = ['add ', 'buy ', 'order ', 'purchase ', 'get me', 'give me'];

    /**
     * @var array<int, string>
     */
    private const PURCHASE_CONTAINED_PHRASES = [
        'i want', "i'd like", 'i would like', "i'll take", 'i will take',
        "i'll buy", 'i will buy', 'give me', 'get me',
    ];

    /**
     * @var array<int, string>
     */
    private const VAGUE_QUANTITY_WORDS = ['a few', 'few', 'some', 'couple', 'several', 'many', 'a bunch'];

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
     * A question about a product — availability, price, or "do you sell /
     * can I see" — must never add to or change a draft. Checked on the
     * *unstripped* text so a trailing "?" is still visible.
     */
    public static function isAvailabilityOrInfoInquiry(string $text): bool
    {
        $normalized = self::normalizeForIntent($text);

        if (str_contains($normalized, '?')) {
            return true;
        }

        foreach (self::INQUIRY_LEADING_WORDS as $word) {
            if (str_starts_with($normalized, $word)) {
                return true;
            }
        }

        foreach (self::INQUIRY_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the message carries an explicit purchase phrase ("I want",
     * "add", "buy", "give me", ...). A product's name being mentioned is
     * never enough on its own — see quantityAndProduct().
     */
    public static function hasPurchaseIntent(string $text): bool
    {
        $normalized = self::normalizeForIntent($text);

        foreach (self::PURCHASE_LEADING_PHRASES as $phrase) {
            if (str_starts_with($normalized, $phrase)) {
                return true;
            }
        }

        foreach (self::PURCHASE_CONTAINED_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The real product an inquiry message is asking about, or null if it
     * doesn't mention one of this business's current products.
     *
     * @param  Collection<int, Product>  $products
     */
    public static function inquiryTarget(string $text, Collection $products): ?Product
    {
        return self::findProduct(self::normalize($text), $products);
    }

    /**
     * Resolves a message that both mentions a real product and carries
     * explicit purchase intent into a product + optional quantity.
     * Returns null for anything else — including a bare product name with
     * no purchase phrase, and any question (see isAvailabilityOrInfoInquiry()).
     *
     * When a quantity is present but ambiguous (more than one number, or a
     * vague word like "some"/"a few"), `quantity` is null and `ambiguous`
     * is true — the caller must ask for clarification rather than guess.
     *
     * @param  Collection<int, Product>  $products
     * @return ?array{product: Product, quantity: ?int, ambiguous: bool}
     */
    public static function quantityAndProduct(string $text, Collection $products): ?array
    {
        if (self::isAvailabilityOrInfoInquiry($text) || ! self::hasPurchaseIntent($text)) {
            return null;
        }

        $normalized = self::normalize($text);
        $product = self::findProduct($normalized, $products);

        if (! $product) {
            return null;
        }

        if (self::hasVagueQuantityWord($normalized)) {
            return ['product' => $product, 'quantity' => null, 'ambiguous' => true];
        }

        preg_match_all('/\d+/', $normalized, $matches);
        $numbers = array_unique($matches[0]);

        if (count($numbers) > 1) {
            return ['product' => $product, 'quantity' => null, 'ambiguous' => true];
        }

        $quantity = $numbers === [] ? null : max(1, min(99, (int) $numbers[0]));

        return ['product' => $product, 'quantity' => $quantity, 'ambiguous' => false];
    }

    private static function hasVagueQuantityWord(string $normalized): bool
    {
        foreach (self::VAGUE_QUANTITY_WORDS as $word) {
            if (str_contains($normalized, $word)) {
                return true;
            }
        }

        return false;
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

    /**
     * Normalizes for exact-phrase and product/number matching — trailing
     * punctuation (including "?") is stripped, since it's irrelevant once
     * intent has already been classified.
     */
    private static function normalize(string $text): string
    {
        return rtrim(mb_strtolower(trim(preg_replace('/\s+/', ' ', $text))), '.!?, ');
    }

    /**
     * Normalizes for intent classification only — keeps punctuation
     * (notably "?"), since isAvailabilityOrInfoInquiry() depends on it.
     */
    private static function normalizeForIntent(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }
}
