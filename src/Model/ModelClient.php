<?php

declare(strict_types=1);

namespace Showcase\Agent\Model;

/**
 * The seam between the agent loop and the model provider. The production implementation is AnthropicModel; tests
 * use ScriptedModel, so the loop is tested without a network or an API key.
 */
interface ModelClient
{
    /**
     * Open a streaming request. Must throw right away if the request cannot start (bad key, rate limit, outage),
     * so the HTTP layer can still answer with a normal error status.
     *
     * @param list<array{role: string, content: mixed}> $messages
     * @param list<array{type: string, text: string, cache?: bool}> $system
     * @param list<array{name: string, description: string, inputSchema: array}> $tools
     */
    public function start(array $system, array $messages, array $tools, bool $allowTools): ModelTurn;

    /**
     * The user message that carries tool results back.
     *
     * @param list<array{id: string, content: string, isError: bool}> $results
     */
    public function toolResultsMessage(array $results): array;
}
