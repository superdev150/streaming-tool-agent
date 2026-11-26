<?php

declare(strict_types=1);

namespace Showcase\Agent\Model;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ToolUseBlock;

/**
 * Claude through the official PHP SDK (`anthropic-ai/sdk`).
 *
 * - Streaming always: text reaches the phone token by token, and long turns never hit an HTTP timeout.
 * - Prompt caching: system blocks marked `cache` get `cache_control` (stable instructions + catalog first, the
 *   per-user part after the breakpoint). In production about 5k tokens are read from cache on every question.
 * - Effort instead of a thinking budget: adaptive thinking is the default on current models; `low` is enough for chat.
 * - `toolChoice: none` on the last allowed round makes the model answer instead of asking for another tool.
 */
final class AnthropicModel implements ModelClient
{
    private Client $client;

    public function __construct(
        ?string $apiKey = null,
        private string $model = 'claude-haiku-5-5',
        private string $effort = 'low',
        private int $maxTokens = 4000,
        ?string $baseUrl = null,
        private ?string $workspaceId = null,
    ) {
        $this->client = new Client(apiKey: $apiKey ?? (string) getenv('ANTHROPIC_API_KEY'), baseUrl: $baseUrl ?: null);
    }

    public static function fromEnv(): self
    {
        $env = fn (string $k) => ($v = getenv($k)) === false || $v === '' ? null : $v;
        return new self(
            apiKey: $env('ANTHROPIC_API_KEY'),
            model: $env('AGENT_MODEL') ?? 'claude-haiku-5-5',
            effort: $env('AGENT_EFFORT') ?? 'low',
            baseUrl: $env('ANTHROPIC_BASE_URL'),
            workspaceId: $env('ANTHROPIC_WORKSPACE_ID'),
        );
    }

    public function start(array $system, array $messages, array $tools, bool $allowTools): ModelTurn
    {
        $stream = $this->client->messages->createStream(
            maxTokens: $this->maxTokens,
            messages: $messages,
            model: $this->model,
            outputConfig: ['effort' => $this->effort],
            system: array_map(fn (array $b) => array_filter([
                'type' => 'text',
                'text' => $b['text'],
                'cacheControl' => !empty($b['cache']) ? ['type' => 'ephemeral'] : null,
            ]), $system),
            tools: $tools,
            toolChoice: $allowTools ? null : ['type' => 'none'],
            workspaceID: $this->workspaceId,
        );

        return new class ($stream) implements ModelTurn {
            private ?TurnResult $result = null;

            public function __construct(private iterable $stream)
            {
            }

            public function text(): iterable
            {
                $acc = MessageAccumulator::forMessages();
                foreach ($this->stream as $event) {
                    $acc->accumulate($event);
                    if ($event instanceof RawContentBlockDeltaEvent && $event->delta instanceof TextDelta && $event->delta->text !== '') {
                        yield $event->delta->text;
                    }
                }
                $msg = $acc->message();
                $u = $msg->usage;
                $this->result = new TurnResult(
                    stopReason: (string) $msg->stopReason,
                    toolCalls: array_values(array_map(
                        fn (ToolUseBlock $b) => new ToolCall($b->id, $b->name, (array) $b->input),
                        array_filter($msg->content, fn ($b) => $b instanceof ToolUseBlock),
                    )),
                    // Full content, thinking blocks and their signatures included: sent back as is
                    assistantContent: $msg->content,
                    usage: [
                        'input' => (int) ($u->inputTokens ?? 0),
                        'output' => (int) ($u->outputTokens ?? 0),
                        'cache_read' => (int) ($u->cacheReadInputTokens ?? 0),
                        'cache_write' => (int) ($u->cacheCreationInputTokens ?? 0),
                    ],
                );
            }

            public function result(): TurnResult
            {
                return $this->result ?? throw new \LogicException('Consume text() first');
            }
        };
    }

    public function toolResultsMessage(array $results): array
    {
        return ['role' => 'user', 'content' => array_map(fn (array $r) => [
            'type' => 'tool_result',
            'toolUseID' => $r['id'],
            'content' => $r['content'],
            'isError' => $r['isError'],
        ], $results)];
    }
}
