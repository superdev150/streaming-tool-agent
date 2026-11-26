<?php

declare(strict_types=1);

namespace Showcase\Agent\Tests;

use PHPUnit\Framework\TestCase;
use Showcase\Agent\Agent;
use Showcase\Agent\Factory;
use Showcase\Agent\KnowledgeBase;
use Showcase\Agent\Model\ScriptedModel;

final class AgentTest extends TestCase
{
    private KnowledgeBase $kb;

    protected function setUp(): void
    {
        $this->kb = KnowledgeBase::fromFile(__DIR__ . '/../data/help-center.json');
    }

    /** @return array{0: list<array>, 1: Agent} */
    private function converse(ScriptedModel $model, string $question = 'how do I export a notebook?', array $config = []): array
    {
        $agent = Factory::agent($model, $this->kb, [['role' => 'user', 'content' => $question]], ['lang' => 'en'], $config);
        $agent->open();
        $events = [];
        $agent->drain(function (array $e) use (&$events) {
            $events[] = $e;
        });
        return [$events, $agent];
    }

    private static function text(array $events): string
    {
        return implode('', array_column(array_filter($events, fn ($e) => $e['t'] === 'text'), 'v'));
    }

    private static function types(array $events): array
    {
        return array_column($events, 't');
    }

    public function testPlainAnswerStreamsAndEndsWithDone(): void
    {
        [$events] = $this->converse(new ScriptedModel([['text' => ['Hi', ' there']]]));

        self::assertSame(['text', 'text', 'done'], self::types($events));
        self::assertSame('Hi there', self::text($events));
    }

    public function testToolRoundTripSendsResultsBackWithTheirIds(): void
    {
        $model = new ScriptedModel([
            ['tools' => [['search_help', ['query' => 'export notebook']], ['get_article', ['ids' => ['export-pdf']]]]],
            ['text' => ['Open Notebooks and long-press it.']],
        ]);
        [$events, $agent] = $this->converse($model);

        self::assertSame(['status', 'status', 'text', 'done'], self::types($events));
        self::assertSame('search', $events[0]['s']);
        self::assertSame('Export a note or notebook as PDF', $events[1]['label']);

        // Second request: [user, assistant (unchanged content), user (both tool results in ONE message)]
        $second = $model->requests[1]['messages'];
        self::assertCount(3, $second);
        self::assertSame(['scripted_turn' => 1, 'text' => '', 'tools' => [['search_help', ['query' => 'export notebook']], ['get_article', ['ids' => ['export-pdf']]]]], $second[1]['content']);
        self::assertSame(['call_1_0', 'call_1_1'], array_column($second[2]['content'], 'id'));
        self::assertStringContainsString('export-pdf: Export a note or notebook as PDF', $second[2]['content'][0]['content']);
        self::assertStringContainsString('Export → PDF', $second[2]['content'][1]['content']);
        self::assertSame(2, $agent->usage['rounds']);
    }

    public function testUsageIsSummedAcrossRounds(): void
    {
        [, $agent] = $this->converse(new ScriptedModel([
            ['tools' => [['get_article', ['ids' => ['plans']]]]],
            ['text' => ['ok']],
        ]));
        self::assertSame(['input' => 200, 'output' => 40, 'cache_read' => 80, 'cache_write' => 80, 'rounds' => 2], $agent->usage);
    }

    public function testEmptyTurnTriggersOneMoreRoundWithToolsDisabled(): void
    {
        // The model "answered" in a hidden block and ended the turn with nothing visible
        $model = new ScriptedModel([
            ['text' => [], 'stop' => 'end_turn'],
            ['text' => ['Here you go.']],
        ]);
        [$events] = $this->converse($model);

        self::assertSame('Here you go.', self::text($events));
        self::assertFalse($model->requests[1]['allowTools']);
        self::assertSame('(Write your reply to me now.)', end($model->requests[1]['messages'])['content']);
    }

    public function testLastAllowedRoundForbidsTools(): void
    {
        $loop = ['tools' => [['search_help', ['query' => 'sync']]]];
        $model = new ScriptedModel([$loop, $loop, ['text' => ['Done.']]]);
        [$events] = $this->converse($model, config: ['max_rounds' => 3]);

        self::assertSame([true, true, false], array_column($model->requests, 'allowTools'));
        self::assertSame('Done.', self::text($events));
    }

    public function testUiOnlyRoundAfterVisibleTextSkipsAnotherModelCall(): void
    {
        $model = new ScriptedModel([[
            'text' => ['Open Settings → Sync.'],
            'tools' => [['app_ui', ['actions' => [['type' => 'open_screen', 'target' => 'settings.sync', 'label' => 'Open Sync']]]]],
        ]]);
        [$events] = $this->converse($model);

        self::assertCount(1, $model->requests);
        self::assertSame(['text', 'ui', 'done'], self::types($events));
    }

    public function testUiRoundWithoutTextStillGetsTheReply(): void
    {
        $model = new ScriptedModel([
            ['tools' => [['app_ui', ['replies' => ['Thanks!']]]]],
            ['text' => ['You are welcome.']],
        ]);
        [$events] = $this->converse($model);

        self::assertCount(2, $model->requests);
        self::assertSame(['ui', 'text', 'done'], self::types($events));
    }

    public function testTextFromSeveralRoundsIsSeparated(): void
    {
        [$events] = $this->converse(new ScriptedModel([
            ['text' => ['Let me check.'], 'tools' => [['get_article', ['ids' => ['trash']]]]],
            ['text' => ['Deleted notes stay 30 days.']],
        ]));
        self::assertSame("Let me check.\n\nDeleted notes stay 30 days.", self::text($events));
    }

    public function testRefusalBecomesAnErrorEvent(): void
    {
        [$events] = $this->converse(new ScriptedModel([['text' => [], 'stop' => 'refusal']]));
        self::assertSame([['t' => 'error', 'code' => 'refused'], ['t' => 'done']], $events);
    }

    public function testConnectionDropMidAnswerIsInterrupted(): void
    {
        [$events] = $this->converse(new ScriptedModel([['text' => ['Half an'], 'throw' => new \RuntimeException('reset')]]));
        self::assertSame(['text', 'error', 'done'], self::types($events));
        self::assertSame('interrupted', $events[1]['code']);
    }

    public function testFailureBeforeAnyTextIsUnavailable(): void
    {
        [$events] = $this->converse(new ScriptedModel([
            ['tools' => [['search_help', ['query' => 'x']]]],
            ['throw' => new \RuntimeException('overloaded')],
        ]));
        self::assertSame(['t' => 'error', 'code' => 'unavailable'], $events[count($events) - 2]);
    }

    public function testOpenFailsLoudlySoTheHttpLayerCanAnswer422Or5xx(): void
    {
        $agent = Factory::agent(new ScriptedModel([], new \RuntimeException('401')), $this->kb, [['role' => 'user', 'content' => 'hi']], []);
        $this->expectException(\RuntimeException::class);
        $agent->open();
    }

    public function testUnknownToolAndBadInputComeBackAsErrorsNotCrashes(): void
    {
        $model = new ScriptedModel([
            ['tools' => [['delete_account', []], ['get_article', ['ids' => ['nope']]], ['search_help', ['query' => '']]]],
            ['text' => ['Sorry.']],
        ]);
        $this->converse($model);

        $results = $model->requests[1]['messages'][2]['content'];
        self::assertSame([true, true, true], array_column($results, 'isError'));
        self::assertSame('Unknown tool delete_account.', $results[0]['content']);
    }

    public function testToolListAndSystemPromptAreStableForCaching(): void
    {
        $model = new ScriptedModel([['tools' => [['search_help', ['query' => 'pdf']]]], ['text' => ['ok']]]);
        $this->converse($model);

        self::assertSame($model->requests[0]['tools'], $model->requests[1]['tools']);
        self::assertSame($model->requests[0]['system'], $model->requests[1]['system']);
        self::assertTrue($model->requests[0]['system'][0]['cache']);
        self::assertArrayNotHasKey('cache', $model->requests[0]['system'][1]);
        self::assertSame(['search_help', 'get_article', 'app_ui'], array_column($model->requests[0]['tools'], 'name'));
    }

    public function testInvalidConversationGivesNoAgent(): void
    {
        self::assertNull(Factory::agent(new ScriptedModel([]), $this->kb, [['role' => 'assistant', 'content' => 'hi']], []));
        self::assertNull(Factory::agent(new ScriptedModel([]), $this->kb, 'not a list', []));
    }
}
