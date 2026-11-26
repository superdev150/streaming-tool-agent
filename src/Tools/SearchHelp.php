<?php

declare(strict_types=1);

namespace Showcase\Agent\Tools;

use Showcase\Agent\KnowledgeBase;

final class SearchHelp implements Tool
{
    public function __construct(private KnowledgeBase $kb)
    {
    }

    public function name(): string
    {
        return 'search_help';
    }

    public function description(): string
    {
        return 'Keyword search over the help-center articles (accents and plurals ignored). Use words the article would '
            . 'contain ("export pdf", "sync offline"). Returns ids and titles, best first; read the ones that fit with '
            . 'get_article. Try other words if nothing fits.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Words to look for.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
            ],
            'required' => ['query'],
        ];
    }

    public function run(array $input, \Closure $emit): ToolOutput
    {
        $query = mb_substr(trim((string) ($input['query'] ?? '')), 0, 120);
        if ($query === '') {
            return new ToolOutput('Empty query.', isError: true);
        }
        $emit(['t' => 'status', 's' => 'search', 'q' => $query]);

        $hits = $this->kb->search($query, max(1, min(8, (int) ($input['limit'] ?? 5))));
        if (!$hits) {
            return new ToolOutput("No articles match \"{$query}\". Try other words.");
        }
        return new ToolOutput(implode("\n", array_map(fn ($h) => "{$h['id']}: {$h['title']}", $hits)));
    }
}
