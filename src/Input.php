<?php

declare(strict_types=1);

namespace Showcase\Agent;

/**
 * Everything from the client is untrusted, and the conversation history too: the server keeps no session, so the app
 * sends the history every time and could send anything. Only known fields with simple values pass, and lengths are
 * capped (they are paid tokens).
 */
final class Input
{
    /**
     * Last N user/assistant messages, consecutive same-role messages joined, starting with the user.
     *
     * @return list<array{role: string, content: string}>
     */
    public static function messages(mixed $raw, int $keep = 16): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, -$keep) as $m) {
            $role = is_array($m) ? ($m['role'] ?? '') : '';
            $content = is_array($m) && is_string($m['content'] ?? null) ? trim($m['content']) : '';
            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $content = mb_substr($content, 0, $role === 'user' ? 1500 : 3000);
            if ($out && end($out)['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n" . $content;
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        while ($out && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        return $out;
    }

    /** Known context fields only. Free text is cut short and stripped of control characters. */
    public static function context(mixed $raw, KnowledgeBase $kb): array
    {
        if (!is_array($raw)) {
            return ['lang' => 'en'];
        }
        $enum = fn ($v, array $list) => is_string($v) && in_array($v, $list, true) ? $v : null;
        $text = fn ($v, int $len) => is_string($v) && trim($v) !== ''
            ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)), 0, $len) : null;

        return array_filter([
            'lang' => $enum($raw['lang'] ?? null, ['en', 'es']) ?? 'en',
            'name' => $text($raw['name'] ?? null, 24),
            'plan' => $enum($raw['plan'] ?? null, ['free', 'plus', 'team']),
            'platform' => $enum($raw['platform'] ?? null, ['ios', 'android', 'web']),
            'app_version' => is_string($raw['app_version'] ?? null) && preg_match('/^\d{1,3}(\.\d{1,3}){0,2}$/', $raw['app_version']) ? $raw['app_version'] : null,
            'screen' => is_string($raw['screen'] ?? null) && $kb->hasScreen($raw['screen']) ? $raw['screen'] : null,
            'notes_count' => is_numeric($raw['notes_count'] ?? null) ? max(0, min(1_000_000, (int) $raw['notes_count'])) : null,
            'offline' => isset($raw['offline']) ? (bool) $raw['offline'] : null,
        ], fn ($v) => $v !== null);
    }
}
