<?php

namespace App\Support\Ai;

/**
 * A real, working AiProviderInterface implementation that calls no
 * external service and costs nothing — unlike NullAiProvider, it actually
 * answers. Used by the conversation simulator (always) and by automated
 * tests that need a predictable reply instead of a thrown exception.
 *
 * It only ever reflects back what is already in the context it is given
 * (real product names/prices/availability from ProductCatalogService), so
 * it structurally cannot invent a product, price, or policy — the same
 * guarantee a real provider must be prompted to honor, this one honors by
 * construction.
 */
class DeterministicFakeAiProvider implements AiProviderInterface
{
    public function generateResponse(AiContext $context, string $customerMessage): string
    {
        $needle = mb_strtolower(trim($customerMessage));

        if ($needle === '') {
            return "I didn't catch that — could you tell me what you're looking for?";
        }

        foreach ($context->products as $product) {
            if (str_contains($needle, mb_strtolower($product['name']))) {
                return $this->describeProduct($product);
            }
        }

        if ($context->products === []) {
            return "We don't have any products available to recommend right now.";
        }

        $names = implode(', ', array_column($context->products, 'name'));

        return "I don't have information about that. Here's what we currently have available: {$names}.";
    }

    /**
     * @param  array{name: string, description: ?string, price: string, available: bool, category: ?string}  $product
     */
    private function describeProduct(array $product): string
    {
        $availability = $product['available'] ? 'in stock' : 'currently unavailable';
        $description = $product['description'] ? ' '.$product['description'] : '';

        return "{$product['name']} is {$availability}, priced at {$product['price']}.{$description}";
    }
}
