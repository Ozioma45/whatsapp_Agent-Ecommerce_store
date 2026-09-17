<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Builds a WhatsApp click-to-chat URL for handing a cart off to a
 * business's WhatsApp number, with a pre-filled order message.
 *
 * This is a pure message/URL builder: it never touches the session or the
 * database itself, so it can only ever describe the exact items and
 * business it was constructed with — the caller (CartController) is
 * responsible for resolving those from the current server-side cart and
 * the current subdomain's business.
 */
class WhatsAppOrder
{
    /**
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float}>  $items
     */
    public function __construct(
        private readonly Business $business,
        private readonly Collection $items,
        private readonly float $subtotal,
    ) {}

    /**
     * The click-to-chat URL, or null when there is nothing orderable —
     * an empty cart, or no usable WhatsApp number configured.
     */
    public function url(): ?string
    {
        if ($this->items->isEmpty()) {
            return null;
        }

        $number = self::normalizeNumber($this->business->setting?->whatsapp_number);

        if (! $number) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode($this->message());
    }

    /**
     * The pre-filled order message text.
     */
    public function message(): string
    {
        $lines = ["Hello {$this->business->name}, I would like to place an order:", ''];

        foreach ($this->items->values() as $index => $item) {
            $product = $item['product'];

            $lines[] = ($index + 1).". {$product->name}";
            $lines[] = "   Qty: {$item['quantity']}";
            $lines[] = '   Price: ₦'.number_format((float) $product->price, 2);
            $lines[] = '   Subtotal: ₦'.number_format($item['subtotal'], 2);
            $lines[] = '';
        }

        $lines[] = 'Total: ₦'.number_format($this->subtotal, 2);
        $lines[] = '';
        $lines[] = 'Please confirm availability and payment details.';

        return implode("\n", $lines);
    }

    /**
     * Normalize a stored WhatsApp number into the digits-only international
     * format WhatsApp's click-to-chat links expect (e.g. "2348012345678").
     *
     * Handles the common Nigerian local format ("0" + 10 digits) and
     * otherwise preserves an already-international number as-is. Returns
     * null rather than guessing when the input isn't a plausible number,
     * so an incorrect destination is never silently generated.
     */
    public static function normalizeNumber(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number);

        if ($digits === '') {
            return null;
        }

        // Nigerian local format: 0XXXXXXXXXX (11 digits) -> 234XXXXXXXXXX.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '234'.substr($digits, 1);
        }

        // Otherwise, only accept it if it already looks like a plausible
        // international number (country code + subscriber number).
        return strlen($digits) >= 10 ? $digits : null;
    }
}
