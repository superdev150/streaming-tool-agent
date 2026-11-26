<?php

declare(strict_types=1);

namespace Showcase\Agent;

use Showcase\Agent\Model\ModelClient;
use Showcase\Agent\Model\ModelTurn;
use Showcase\Agent\Tools\Tool;
use Showcase\Agent\Tools\ToolOutput;

/**
 * The agent loop: stream the model's answer, run the tools it asks for, send the results back, repeat, and stop
 * cleanly. It emits events for the client as it goes:
 *
 *   {"t":"text","v":"…"}                     visible reply, token by token
 *   {"t":"status","s":"search","q":"…"}      what the agent is doing (the app shows "Searching…")
 *   {"t":"ui","actions":[…],"replies":[…]}   validated buttons / suggested replies
 *   {"t":"error","code":"refused|interrupted|unavailable"}
 *   {"t":"done"}                             always last
 *
 * Things learned in production that the loop handles:
 *  - With current Claude models, text written BEFORE a tool call comes back as a hidden thinking block, not as text.
 *    The prompt therefore asks for all tools first, reply last. If a turn still ends with nothing visible, one extra
 *    round asks for the reply with tools disabled.
 *  - The last allowed round is sent with tool_choice none, so a long chain of tool calls always ends in an answer.
 *  - The assistant's content goes back unchanged with the tool results. Thinking blocks carry signatures, and an
 *    edited one is rejected.
 *  - A UI-only tool call made after the reply is visible needs no further model round. Skipping it saves a full
 *    request per question.
 *  - The first request is opened before the HTTP response starts (open()), so a bad key or a rate limit becomes a
 *    normal JSON error instead of a 200 that ends early.
 */
final class Agent
{
    public const DEFAULTS = [
        'max_rounds' => 5,
        'history' => 16,
    ];

    /** @var array<string, Tool> */
    private array $tools = [];

    private ?ModelTurn $first = null;

    /** @var array{input: int, output: int, cache_read: int, cache_write: int, rounds: int} */
    public array $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'rounds' => 0];

    /**
     * @param list<array{role: string, content: string}> $messages already cleaned (Input::messages)
     * @param list<Tool> $tools
     */
    public function __construct(
        private ModelClient $model,
        private Prompt $prompt,
        private array $messages,
        array $tools,
        private array $config = self::DEFAULTS,
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
        $this->config += self::DEFAULTS;
    }

    /** Open the first model request. Throws if it cannot start: answer the HTTP request with an error then. */
    public function open(): void
    {
        $this->first = $this->model->start($this->prompt->system(), $this->messages, $this->toolSpecs(), true);
    }

    /** @param \Closure(array): void $emit */
    public function drain(\Closure $emit): void
    {
        $wroteText = false;
        try {
            $max = (int) $this->config['max_rounds'];
            for ($round = 1; $round <= $max; $round++) {
                $this->usage['rounds'] = $round;
                $turn = ($round === 1 && $this->first)
                    ? $this->first
                    : $this->model->start($this->prompt->system(), $this->messages, $this->toolSpecs(), $round < $max);

                $roundText = false;
                foreach ($turn->text() as $delta) {
                    if (!$roundText && $wroteText) {
                        $emit(['t' => 'text', 'v' => "\n\n"]);   // a later round continues the reply
                    }
                    $roundText = $wroteText = true;
                    $emit(['t' => 'text', 'v' => $delta]);
                }
                $result = $turn->result();
                foreach (['input', 'output', 'cache_read', 'cache_write'] as $k) {
                    $this->usage[$k] += $result->usage[$k];
                }

                if ($result->stopReason === 'refusal') {
                    $emit(['t' => 'error', 'code' => 'refused']);
                    break;
                }

                if ($result->stopReason === 'tool_use' && $result->toolCalls) {
                    $results = [];
                    $allTerminal = true;
                    foreach ($result->toolCalls as $call) {
                        $out = $this->runTool($call->name, $call->input, $emit);
                        $allTerminal = $allTerminal && $out->terminal;
                        $results[] = ['id' => $call->id, 'content' => $out->content, 'isError' => $out->isError];
                    }
                    // Every tool_use needs its tool_result in the very next message, all in ONE message
                    $this->messages[] = ['role' => 'assistant', 'content' => $result->assistantContent];
                    $this->messages[] = $this->model->toolResultsMessage($results);

                    if ($allTerminal && $roundText) {
                        break;
                    }
                    continue;
                }

                if (!$wroteText && $round < $max) {
                    // Nothing visible yet (the reply went into a hidden block before a tool call): ask for it
                    $this->messages[] = ['role' => 'assistant', 'content' => $result->assistantContent];
                    $this->messages[] = ['role' => 'user', 'content' => '(Write your reply to me now.)'];
                    $max = $round + 1;
                    continue;
                }
                break;
            }
        } catch (\Throwable $e) {
            error_log('agent: ' . $e::class . ' ' . $e->getMessage());
            $emit(['t' => 'error', 'code' => $wroteText ? 'interrupted' : 'unavailable']);
        }
        $emit(['t' => 'done']);
    }

    private function runTool(string $name, array $input, \Closure $emit): ToolOutput
    {
        $tool = $this->tools[$name] ?? null;
        if (!$tool) {
            return new ToolOutput("Unknown tool {$name}.", isError: true);
        }
        try {
            return $tool->run($input, $emit);
        } catch (\Throwable $e) {
            error_log("agent tool {$name}: " . $e->getMessage());
            return new ToolOutput("The tool failed. Answer without it or try other input.", isError: true);
        }
    }

    /** Same order every request: the tool list is part of the cached prefix. */
    private function toolSpecs(): array
    {
        return array_values(array_map(fn (Tool $t) => [
            'name' => $t->name(),
            'description' => $t->description(),
            'inputSchema' => $t->inputSchema(),
        ], $this->tools));
    }
}
