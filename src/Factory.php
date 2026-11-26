<?php

declare(strict_types=1);

namespace Showcase\Agent;

use Showcase\Agent\Model\ModelClient;
use Showcase\Agent\Tools\AppUi;
use Showcase\Agent\Tools\GetArticle;
use Showcase\Agent\Tools\SearchHelp;

/** Wires an agent for one request. */
final class Factory
{
    public static function agent(ModelClient $model, KnowledgeBase $kb, mixed $rawMessages, mixed $rawContext, array $config = []): ?Agent
    {
        $config += Agent::DEFAULTS;
        $messages = Input::messages($rawMessages, (int) $config['history']);
        if (!$messages || end($messages)['role'] !== 'user') {
            return null;
        }
        $prompt = new Prompt($kb, Input::context($rawContext, $kb));

        return new Agent($model, $prompt, $messages, [new SearchHelp($kb), new GetArticle($kb), new AppUi($kb)], $config);
    }
}
