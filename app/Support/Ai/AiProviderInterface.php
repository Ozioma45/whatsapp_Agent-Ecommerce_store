<?php

namespace App\Support\Ai;

/**
 * The seam between the assistant's business logic and whichever AI
 * provider is eventually used. Nothing in the application depends on a
 * specific vendor; a concrete provider is added (and bound to this
 * interface) in a later phase. Credentials for it will come from
 * environment configuration only — never from business settings.
 */
interface AiProviderInterface
{
    /**
     * Generate a reply to a customer's message, given the (already
     * tenant-scoped) context for the one business being spoken for.
     */
    public function generateResponse(AiContext $context, string $customerMessage): string;
}
