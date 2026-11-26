<?php

/**
 * POST /  { messages: [{role, content}…], context: {…} }  →  application/x-ndjson stream
 *
 *   ANTHROPIC_API_KEY=sk-ant-… composer serve
 *   curl -N localhost:8080 -d '{"messages":[{"role":"user","content":"How do I export a notebook?"}]}'
 *
 * Errors before the stream starts are plain JSON {code} with a status, so the app can show a proper message:
 * 422 invalid · 429 busy · 502/503 unavailable. In production this endpoint also checks an app key, rate-limits per
 * user/IP/day and logs token usage per question; see the README.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Anthropic\Core\Exceptions\RateLimitException;
use Showcase\Agent\Factory;
use Showcase\Agent\KnowledgeBase;
use Showcase\Agent\Model\AnthropicModel;
use Showcase\Agent\Ndjson;

function fail(string $code, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['code' => $code]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('method_not_allowed', 405);
}
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    fail('invalid', 422);
}

$kb = KnowledgeBase::fromFile(__DIR__ . '/../data/help-center.json');
$agent = Factory::agent(AnthropicModel::fromEnv(), $kb, $body['messages'] ?? null, $body['context'] ?? null);
if (!$agent) {
    fail('invalid', 422);
}

// Open the first request BEFORE sending headers: failures here are still a clean HTTP error
try {
    $agent->open();
} catch (RateLimitException) {
    fail('busy', 429);
} catch (\Throwable $e) {
    error_log('agent open: ' . $e->getMessage());
    fail('unavailable', 502);
}

foreach (Ndjson::headers() as $name => $value) {
    header("{$name}: {$value}");
}
while (ob_get_level() > 0) {
    ob_end_flush();
}
ignore_user_abort(true);   // finish the turn and log usage even if the phone loses signal

$started = microtime(true);
$agent->drain(Ndjson::emitter());
error_log('agent usage ' . json_encode($agent->usage + ['ms' => (int) ((microtime(true) - $started) * 1000)]));
