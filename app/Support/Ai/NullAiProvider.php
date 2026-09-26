<?php

namespace App\Support\Ai;

use LogicException;

/**
 * Placeholder bound until a real provider exists. It deliberately fails
 * loudly rather than returning canned text that could be mistaken for an
 * AI answer.
 */
class NullAiProvider implements AiProviderInterface
{
    public function generateResponse(AiContext $context, string $customerMessage): string
    {
        throw new LogicException('No AI provider has been configured yet.');
    }
}
