<?php

declare(strict_types=1);

namespace Showcase\Agent\Tools;

use Showcase\Agent\KnowledgeBase;

/**
 * Lets the model put real UI under its reply: buttons that open screens or articles, suggested follow-up replies,
 * and a hand-off to a human. The app renders them natively.
 *
 * Nothing the model sends reaches the app unchecked. Action types come from a fixed list, targets must exist, and
 * labels are trimmed. A hallucinated screen id just disappears.
 */
final class AppUi implements Tool
{
    public const ACTIONS = ['open_screen', 'open_article', 'contact_support'];

    public function __construct(private KnowledgeBase $kb)
    {
    }

    public function name(): string
    {
        return 'app_ui';
    }

    public function description(): string
    {
        return 'Show interactive elements below your reply. Call it at most once, together with your other tools and '
            . 'BEFORE writing the reply (text written before a tool call is not shown).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'actions' => [
                    'type' => 'array', 'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => self::ACTIONS],
                            'target' => ['type' => 'string', 'description' => 'Screen id or article id; empty for contact_support.'],
                            'label' => ['type' => 'string', 'description' => 'Button text, 2-5 words, in the user\'s language.'],
                        ],
                        'required' => ['type', 'label'],
                    ],
                ],
                'replies' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string']],
                'handoff' => ['type' => 'boolean', 'description' => 'The user needs a human (billing dispute, data loss, angry).'],
            ],
        ];
    }

    public function run(array $input, \Closure $emit): ToolOutput
    {
        $ui = $this->clean($input);
        if ($ui) {
            $emit(['t' => 'ui'] + $ui);
        }
        return new ToolOutput('Shown below your reply. Now write your reply.', terminal: true);
    }

    /** @internal public for tests */
    public function clean(array $in): array
    {
        $actions = [];
        foreach (array_slice((array) ($in['actions'] ?? []), 0, 3) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $type = (string) ($a['type'] ?? '');
            $target = trim((string) ($a['target'] ?? ''));
            $label = mb_substr(trim((string) ($a['label'] ?? '')), 0, 40);
            $ok = $label !== '' && match ($type) {
                'open_screen' => $this->kb->hasScreen($target),
                'open_article' => $this->kb->article($target) !== null,
                'contact_support' => ($target = '') === '',
                default => false,
            };
            if ($ok) {
                $actions[] = ['type' => $type, 'target' => $target, 'label' => $label];
            }
        }
        $replies = array_values(array_filter(array_map(
            fn ($r) => is_string($r) ? mb_substr(trim($r), 0, 60) : '',
            array_slice((array) ($in['replies'] ?? []), 0, 3),
        )));

        return array_filter([
            'actions' => $actions,
            'replies' => $replies,
            'handoff' => !empty($in['handoff']) ? true : null,
        ]);
    }
}
