<?php

namespace App\Support\Ai\Orders;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\WhatsAppOrderDraft;
use App\Support\OrderCreationService;
use Illuminate\Support\Collection;

/**
 * Turns a customer's message into order-draft actions (add, change
 * quantity, remove, clear, review, confirm, cancel) using nothing but
 * deterministic rules (OrderIntentParser) and the store's persisted state.
 *
 * No AI provider is involved anywhere in this class — the model is never
 * asked to remember, price, or total an order, and it cannot create or
 * modify one. Every product referenced is re-resolved from the given
 * $products collection (the business's own current, available catalogue),
 * so a price or availability the AI or the customer merely typed is
 * structurally impossible to act on.
 *
 * With $simulate = true (used only by the conversation simulator), every
 * rule and validation still runs identically, but the final step of
 * actually creating an Order is skipped in favour of a clearly labelled
 * preview — see confirm().
 */
class OrderConversationHandler
{
    public function __construct(private readonly OrderCreationService $orderCreation) {}

    /**
     * @param  Collection<int, Product>  $products  This business's currently available products, keyed by id.
     */
    public function handle(Business $business, OrderDraftStore $store, Collection $products, string $text, string $channelPhone, bool $simulate): OrderHandlingResult
    {
        $state = $store->get();

        if (blank($state['customer_phone']) && $channelPhone !== '') {
            $state['customer_phone'] = $channelPhone;
        }

        $hasDraftInProgress = $state['items'] !== [] || $state['pending_action'] !== null;

        if (OrderIntentParser::isCancellation($text) && $hasDraftInProgress) {
            $store->delete();

            return OrderHandlingResult::reply("Okay, I've cancelled your order.");
        }

        if ($state['pending_action'] === WhatsAppOrderDraft::PENDING_NAME) {
            return $this->collectName($business, $store, $products, $state, $text, $simulate);
        }

        if ($state['pending_action'] === WhatsAppOrderDraft::PENDING_CONFIRMATION && OrderIntentParser::isConfirmation($text)) {
            return $this->confirm($business, $store, $products, $state, $simulate);
        }

        if (OrderIntentParser::isClear($text)) {
            if ($state['items'] === []) {
                return OrderHandlingResult::none();
            }

            $state['items'] = [];
            $state['pending_action'] = null;
            $state['confirmation_snapshot'] = null;
            $store->save($state);

            return OrderHandlingResult::reply("Your order has been cleared. Let me know what you'd like to order.");
        }

        $removal = OrderIntentParser::removalTarget($text, $products);

        if ($removal) {
            unset($state['items'][$removal->id]);
            $state['pending_action'] = null;
            $state['confirmation_snapshot'] = null;
            $store->save($state);

            return OrderHandlingResult::reply($this->summaryReply($state, $products, "Removed {$removal->name} from your order."));
        }

        $match = OrderIntentParser::quantityAndProduct($text, $products);

        if ($match) {
            $productId = $match['product']->id;
            $existing = $state['items'][$productId] ?? null;
            $quantity = $match['quantity'] ?? $existing ?? 1;
            $state['items'][$productId] = max(1, min(99, $quantity));
            $state['pending_action'] = null;
            $state['confirmation_snapshot'] = null;
            $store->save($state);

            return OrderHandlingResult::reply($this->summaryReply(
                $state,
                $products,
                "Added {$state['items'][$productId]} x {$match['product']->name} to your order."
            ));
        }

        if (OrderIntentParser::isCheckoutRequest($text) || OrderIntentParser::isReviewRequest($text)) {
            return $this->presentForConfirmation($business, $store, $products, $state, $simulate);
        }

        if ($state['pending_action'] === WhatsAppOrderDraft::PENDING_CONFIRMATION) {
            return OrderHandlingResult::reply(
                "Reply CONFIRM to place this order, or CANCEL to discard it.\n\n".$this->renderSummary($state, $products)
            );
        }

        return OrderHandlingResult::none();
    }

    /**
     * The draft's current, revalidated view — for the simulator to display
     * alongside its chat reply, without mutating anything.
     *
     * @param  Collection<int, Product>  $products
     * @return array{lines: array<int, array{name: string, quantity: int, unit_price: string, subtotal: string}>, total: string}
     */
    public function currentDraftView(OrderDraftStore $store, Collection $products): array
    {
        $state = $store->get();
        ['lines' => $lines, 'total' => $total] = $this->revalidate($state, $products);

        return [
            'lines' => $lines->map(fn (array $line) => [
                'name' => $line['product']->name,
                'quantity' => $line['quantity'],
                'unit_price' => number_format((float) $line['product']->price, 2),
                'subtotal' => number_format($line['subtotal'], 2),
            ])->all(),
            'total' => number_format($total, 2),
        ];
    }

    /**
     * @param  array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}  $state
     * @param  Collection<int, Product>  $products
     */
    private function collectName(Business $business, OrderDraftStore $store, Collection $products, array $state, string $text, bool $simulate): OrderHandlingResult
    {
        if (OrderIntentParser::isClear($text)) {
            $state['items'] = [];
            $state['pending_action'] = null;
            $state['confirmation_snapshot'] = null;
            $store->save($state);

            return OrderHandlingResult::reply("Your order has been cleared. Let me know what you'd like to order.");
        }

        // A control word ("confirm", "checkout", ...) sent while we're
        // waiting for a name is never actually the customer's name — it
        // almost always means they tried to confirm before providing one.
        if (OrderIntentParser::isConfirmation($text) || OrderIntentParser::isCheckoutRequest($text) || OrderIntentParser::isReviewRequest($text)) {
            return OrderHandlingResult::reply('I still need your name before I can place this order. What name should I put on it?');
        }

        $name = trim($text);

        if ($name === '' || mb_strlen($name) > 100) {
            return OrderHandlingResult::reply('Please tell me the name to put on this order.');
        }

        $state['customer_name'] = $name;
        $store->save($state);

        return $this->presentForConfirmation($business, $store, $products, $state, $simulate);
    }

    /**
     * @param  array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}  $state
     * @param  Collection<int, Product>  $products
     */
    private function presentForConfirmation(Business $business, OrderDraftStore $store, Collection $products, array $state, bool $simulate): OrderHandlingResult
    {
        ['lines' => $lines, 'total' => $total, 'state' => $state] = $this->revalidate($state, $products);

        if ($lines->isEmpty()) {
            $state['pending_action'] = null;
            $state['confirmation_snapshot'] = null;
            $store->save($state);

            return OrderHandlingResult::reply("You haven't added anything available yet. What would you like to order?");
        }

        if (blank($state['customer_name'])) {
            $state['pending_action'] = WhatsAppOrderDraft::PENDING_NAME;
            $store->save($state);

            return OrderHandlingResult::reply('Great! What name should I put on this order?');
        }

        $state['pending_action'] = WhatsAppOrderDraft::PENDING_CONFIRMATION;
        $state['confirmation_snapshot'] = $this->snapshot($lines, $total);
        $store->save($state);

        return OrderHandlingResult::reply(
            $this->renderSummaryFromLines($lines, $total, $state['customer_name'])."\n\nReply CONFIRM to place this order, or CANCEL to discard it."
        );
    }

    /**
     * @param  array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}  $state
     * @param  Collection<int, Product>  $products
     */
    private function confirm(Business $business, OrderDraftStore $store, Collection $products, array $state, bool $simulate): OrderHandlingResult
    {
        ['lines' => $lines, 'total' => $total, 'state' => $state] = $this->revalidate($state, $products);

        if ($lines->isEmpty()) {
            $state['pending_action'] = null;
            $state['confirmation_snapshot'] = null;
            $store->save($state);

            return OrderHandlingResult::reply('Everything in your order is no longer available. Please choose something else.');
        }

        $currentSnapshot = $this->snapshot($lines, $total);

        if ($currentSnapshot !== $state['confirmation_snapshot']) {
            $state['confirmation_snapshot'] = $currentSnapshot;
            $store->save($state);

            return OrderHandlingResult::reply(
                "Some details changed since I last showed you this order:\n\n"
                .$this->renderSummaryFromLines($lines, $total, $state['customer_name'])
                ."\n\nReply CONFIRM to place this order, or CANCEL to discard it."
            );
        }

        if (blank($state['customer_name'])) {
            $state['pending_action'] = WhatsAppOrderDraft::PENDING_NAME;
            $store->save($state);

            return OrderHandlingResult::reply('Before I place this order, what name should I use?');
        }

        if ($simulate) {
            $store->delete();

            return OrderHandlingResult::reply(
                "(Simulated) Your order would now be placed:\n\n"
                .$this->renderSummaryFromLines($lines, $total, $state['customer_name'])
                ."\n\nNo real order was created.",
                simulatedOrder: true
            );
        }

        try {
            $order = $this->orderCreation->create(
                business: $business,
                items: $lines,
                customerName: $state['customer_name'],
                customerPhone: $state['customer_phone'] ?: 'unknown',
                source: Order::SOURCE_WHATSAPP_AI,
            );
        } catch (\Throwable $e) {
            report($e);

            return OrderHandlingResult::reply('Sorry, something went wrong placing your order. Please try again shortly.');
        }

        $store->delete();

        return OrderHandlingResult::reply(
            "Thank you! Your order {$order->order_number} has been received and is pending — total ".number_format($total, 2).
            ". We'll be in touch about payment.",
            order: $order
        );
    }

    /**
     * @param  array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}  $state
     * @param  Collection<int, Product>  $products
     */
    private function summaryReply(array $state, Collection $products, string $prefix): string
    {
        return $prefix."\n\n".$this->renderSummary($state, $products);
    }

    /**
     * @param  array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}  $state
     * @param  Collection<int, Product>  $products
     */
    private function renderSummary(array $state, Collection $products): string
    {
        ['lines' => $lines, 'total' => $total] = $this->revalidate($state, $products);

        return $this->renderSummaryFromLines($lines, $total, $state['customer_name']);
    }

    /**
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float}>  $lines
     */
    private function renderSummaryFromLines(Collection $lines, float $total, ?string $customerName): string
    {
        $itemLines = $lines->map(fn (array $line) => "- {$line['quantity']} x {$line['product']->name} @ "
            .number_format((float) $line['product']->price, 2).' = '.number_format($line['subtotal'], 2)
        )->implode("\n");

        $name = $customerName ? "Name: {$customerName}\n" : '';

        return "Here's your order summary:\n{$itemLines}\n\n{$name}Total: ".number_format($total, 2);
    }

    /**
     * Recomputes the draft's lines against the business's current, available
     * products only — anything deleted or made unavailable since it was
     * added is silently dropped, and prices always come from $products,
     * never from anything stored on the draft itself.
     *
     * @param  array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}  $state
     * @param  Collection<int, Product>  $products
     * @return array{lines: Collection<int, array{product: Product, quantity: int, subtotal: float}>, total: float, state: array{items: array<int, int>, customer_name: ?string, customer_phone: ?string, pending_action: ?string, confirmation_snapshot: ?string}}
     */
    private function revalidate(array $state, Collection $products): array
    {
        $lines = collect();
        $prunedItems = [];

        foreach ($state['items'] as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product) {
                continue;
            }

            $prunedItems[$productId] = $quantity;
            $lines->push([
                'product' => $product,
                'quantity' => $quantity,
                'subtotal' => (float) $product->price * $quantity,
            ]);
        }

        $state['items'] = $prunedItems;

        return ['lines' => $lines, 'total' => (float) $lines->sum('subtotal'), 'state' => $state];
    }

    /**
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float}>  $lines
     */
    private function snapshot(Collection $lines, float $total): string
    {
        $parts = $lines
            ->map(fn (array $line) => $line['product']->id.':'.$line['quantity'].':'.number_format((float) $line['product']->price, 2, '.', ''))
            ->sort()
            ->implode('|');

        return $parts.'#'.number_format($total, 2, '.', '');
    }
}
