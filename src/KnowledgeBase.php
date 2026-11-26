<?php

declare(strict_types=1);

namespace Showcase\Agent;

use Showcase\Agent\Search\KeywordIndex;

/**
 * The data the agent is allowed to answer from: help-center articles and the app's screens. In production this is the
 * published catalog of the app, read from a bucket and cached for 10 minutes, so new content reaches the agent
 * without a deploy.
 */
final class KnowledgeBase
{
    /** @var array<string, array{id: string, title: string, text: string, screen?: string}> */
    private array $articles = [];

    /** @var array<string, string> screen id → label */
    private array $screens;

    private KeywordIndex $index;

    public function __construct(array $articles, array $screens)
    {
        foreach ($articles as $a) {
            $this->articles[$a['id']] = $a;
        }
        $this->screens = $screens;
        $this->index = new KeywordIndex($this->articles);
    }

    public static function fromFile(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return new self($data['articles'], $data['screens']);
    }

    public function article(string $id): ?array
    {
        return $this->articles[$id] ?? null;
    }

    public function search(string $query, int $limit): array
    {
        return $this->index->search($query, $limit);
    }

    public function hasScreen(string $id): bool
    {
        return isset($this->screens[$id]);
    }

    /** Compact list for the system prompt (stable order: it sits in the cached prefix). */
    public function forPrompt(): string
    {
        $lines = ['Articles (id: title):'];
        foreach ($this->articles as $a) {
            $lines[] = "- {$a['id']}: {$a['title']}";
        }
        $lines[] = '';
        $lines[] = 'Screens (id: label):';
        foreach ($this->screens as $id => $label) {
            $lines[] = "- {$id}: {$label}";
        }
        return implode("\n", $lines);
    }
}
