<?php

declare(strict_types=1);

namespace Showcase\Agent\Model;

/** How one model turn ended. */
final class TurnResult
{
    /**
     * @param 'end_turn'|'tool_use'|'max_tokens'|'refusal'|string $stopReason
     * @param list<ToolCall> $toolCalls
     * @param mixed $assistantContent the turn's full content, opaque to the agent. It is sent back unchanged so that
     *                                thinking blocks keep their signatures (the API rejects edited ones)
     * @param array{input: int, output: int, cache_read: int, cache_write: int} $usage
     */
    public function __construct(
        public readonly string $stopReason,
        public readonly array $toolCalls,
        public readonly mixed $assistantContent,
        public readonly array $usage,
    ) {
    }
}
