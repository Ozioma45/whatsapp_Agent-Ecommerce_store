<?php

namespace App\Support;

use App\Models\Order;

/**
 * Builds a WhatsApp click-to-chat URL for handing a placed order off to a
 * business's WhatsApp number, with a pre-filled order message.
 *
 * This is a pure message/URL builder built entirely from an already-saved
 * Order (and its items) — the database is the single source of truth for
 * every product name, price, and total it prints, never anything supplied
 * by the browser. See CartController::checkout(), which creates the order
 * before this is ever used.
 */
class WhatsAppOrder
{
    public function __construct(private readonly Order $order) {}

    /**
     * The click-to-chat URL, or null when the business has no usable
     * WhatsApp number configured.
     */
    public function url(): ?string
    {
        $number = self::normalizeNumber($this->order->business->setting?->whatsapp_number);

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
        $order = $this->order;

        $lines = [
            "Hello {$order->business->name}, I would like to place an order.",
            '',
            "Order: {$order->order_number}",
            '',
        ];

        foreach ($order->items as $index => $item) {
            $lines[] = ($index + 1).". {$item->product_name}";
            $lines[] = "   Qty: {$item->quantity}";
            $lines[] = '   Price: ₦'.number_format((float) $item->unit_price, 2);
            $lines[] = '   Subtotal: ₦'.number_format((float) $item->subtotal, 2);
            $lines[] = '';
        }

        $lines[] = 'Total: ₦'.number_format((float) $order->total, 2);

        if ($order->customer_name || $order->customer_phone) {
            $lines[] = '';
            $lines[] = 'Customer:';

            if ($order->customer_name) {
                $lines[] = "Name: {$order->customer_name}";
            }

            if ($order->customer_phone) {
                $lines[] = "WhatsApp: {$order->customer_phone}";
            }
        }

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
