<?php

declare(strict_types=1);

namespace Showcase\Agent\Tools;

use Showcase\Agent\KnowledgeBase;

final class GetArticle implements Tool
{
    public function __construct(private KnowledgeBase $kb)
    {
    }

    public function name(): string
    {
        return 'get_article';
    }

    public function description(): string
    {
        return 'Full text of up to 3 help-center articles by id. Everything you tell the user about how the app works must '
            . 'come from text returned by this tool in this conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 3],
            ],
            'required' => ['ids'],
        ];
    }

    public function run(array $input, \Closure $emit): ToolOutput
    {
        $out = [];
        $found = [];
        foreach (array_slice(array_unique((array) ($input['ids'] ?? [])), 0, 3) as $id) {
            $a = $this->kb->article((string) $id);
            if (!$a) {
                $out[] = "{$id}: not found (use an id from the catalog or from search_help).";
                continue;
            }
            $found[] = $a['title'];
            $out[] = "id: {$a['id']} | {$a['title']}\n{$a['text']}";
        }
        if ($found) {
            $emit(['t' => 'status', 's' => 'read', 'label' => implode(', ', $found)]);
        }
        return new ToolOutput(implode("\n\n", $out) ?: 'No ids given.', isError: !$found);
    }
}
