<?php

namespace App\Services\Ai;

/**
 * The only part of talking to an AI that differs between brands.
 *
 * Everything the site has decided - what counts as news, where a story
 * happened, what Malaysians follow - lives in the playbook and is sent to
 * whichever model answers. What changes between one brand and the next is
 * narrow and mechanical: the address, the shape of the request, how strictly
 * the model has to be told to return JSON, and what an error looks like.
 *
 * Keeping that behind one interface is what makes swapping brands a day's work
 * rather than a rewrite. Add an adapter, run the bench, compare the numbers.
 */
interface ModelAdapter
{
    /** Short stable name used in config, the bench and the logs. */
    public function key(): string;

    /** The specific model this adapter is pointed at. */
    public function model(): string;

    /** True when a key is configured, so the panel can say what is usable. */
    public function isConfigured(): bool;

    /**
     * Send a prompt, return the raw text of the reply.
     *
     * @throws \RuntimeException when the model cannot be reached or answers
     *         with something unusable. The caller decides whether to retry.
     */
    public function complete(string $prompt, float $temperature = 0.3): string;

    /**
     * What the call cost, if the brand reports it, keyed however that brand
     * reports it. Empty is a fine answer.
     *
     * @return array<string, mixed>
     */
    public function lastUsage(): array;
}
