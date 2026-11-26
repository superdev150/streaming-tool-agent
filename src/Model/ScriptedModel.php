<?php

declare(strict_types=1);

namespace Showcase\Agent\Model;

/**
 * A model that plays back a script, for tests and offline demos. Each turn is
 * ['text' => [deltas], 'tools' => [[name, input]], 'stop' => 'end_turn'|'tool_use'|…, 'throw' => ?Throwable]
 * (`throw` fails after the text deltas: a connection dropped mid-answer).
 *
 * Every request it receives is kept in `$requests`, so tests can assert what the loop sent.
 */
final class ScriptedModel implements ModelClient
{
    /** @var list<array{system: array, messages: array, tools: array, allowTools: bool}> */
    public array $requests = [];

    /** @param list<array> $turns */
    public function __construct(private array $turns, private ?\Throwable $failOnStart = null)
    {
    }

    public function start(array $system, array $messages, array $tools, bool $allowTools): ModelTurn
    {
        if ($this->failOnStart) {
            throw $this->failOnStart;
        }
        $this->requests[] = compact('system', 'messages', 'tools', 'allowTools');
        $turn = array_shift($this->turns) ?? ['text' => ['(script ended)'], 'stop' => 'end_turn'];
        $n = count($this->requests);

        return new class ($turn, $n) implements ModelTurn {
            private ?TurnResult $result = null;

            public function __construct(private array $turn, private int $n)
            {
            }

            public function text(): iterable
            {
                foreach ($this->turn['text'] ?? [] as $delta) {
                    yield $delta;
                }
                if (isset($this->turn['throw'])) {
                    throw $this->turn['throw'];
                }
                $calls = [];
                foreach ($this->turn['tools'] ?? [] as $i => [$name, $input]) {
                    $calls[] = new ToolCall("call_{$this->n}_{$i}", $name, $input);
                }
                $this->result = new TurnResult(
                    $this->turn['stop'] ?? ($calls ? 'tool_use' : 'end_turn'),
                    $calls,
                    ['scripted_turn' => $this->n, 'text' => implode('', $this->turn['text'] ?? []), 'tools' => $this->turn['tools'] ?? []],
                    ['input' => 100, 'output' => 20, 'cache_read' => $this->n > 1 ? 80 : 0, 'cache_write' => $this->n === 1 ? 80 : 0],
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
        return ['role' => 'user', 'content' => array_map(fn ($r) => ['type' => 'tool_result'] + $r, $results)];
    }
}
