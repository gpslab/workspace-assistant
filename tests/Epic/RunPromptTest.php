<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Config;
use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\MasterRecord;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Epic\RunPrompt;
use GpsLab\WorkspaceAssistant\ForgeOwners;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/OrchestratorTest.php';

#[CoversClass(RunPrompt::class)]
#[CoversClass(Run::class)]
final class RunPromptTest extends TestCase
{
    private const string HEAD = '0123456789abcdef0123456789abcdef01234567';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/runprompt-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function runPrompt(?FakeGitHub $gh = null, ?Database $db = null, string $forge = Config::FORGE_GITHUB, ?ForgeOwners $owners = null): RunPrompt
    {
        $config = new Config(
            botToken: 't',
            allowedUserIds: [555],
            repoPath: $this->root,
            forgeRepo: '',
            claudeBin: 'claude',
            claudeTimeout: 0,
            claudeStallTimeout: 900,
            claudeExtraArgs: [],
            claudeAppendSystemPrompt: '',
            stateDir: $this->root.'/state',
            outboxDir: $this->root.'/outbox',
            inboxDir: $this->root.'/inbox',
            pollTimeout: 1,
            gitTimeout: 30,
            forgeOwners: $owners ?? new ForgeOwners(['gpslab']),
            forge: $forge,
        );

        return new RunPrompt($db ?? new Database($this->root), $config, $gh);
    }

    private function saveCold(): void
    {
        ColdFindings::save(ColdFindings::path($this->root.'/state', 'demo', 'ws#5'), [
            'task' => 'ws#4',
            'pr' => 'ws#5',
            'head' => self::HEAD,
            'findings' => [],
        ]);
    }

    public function testEpicReviewPromptCarriesTheColdFindingsHead(): void
    {
        $this->saveCold();

        $prompt = $this->runPrompt()->forEpicRun('demo', Run::REVIEW, 'ws#5');

        self::assertStringContainsString('Reviewed commit: '.self::HEAD, $prompt);
        self::assertStringContainsString('-f commit_id='.self::HEAD, $prompt);
        self::assertStringContainsString('git diff <merge-base>...'.self::HEAD, $prompt);
    }

    public function testFinalPromptCarriesTheTailsRoundsFromTheDatabase(): void
    {
        $db = new Database($this->root);
        $db->epicCreate('demo', 1, 0, [['task' => 'ws#1', 'after' => []]], []);
        $pdo = (new \ReflectionProperty(Database::class, 'pdo'))->getValue($db);
        self::assertInstanceOf(\PDO::class, $pdo);
        $prompt = $this->runPrompt(null, $db);

        self::assertStringContainsString('Tails rounds: 0 of '.Database::MAX_TAILS_ROUNDS.' used', $prompt->forEpicRun('demo', Run::FINAL, null));
        self::assertStringNotContainsString('Tails rounds:', $prompt->forEpicRun('demo', Run::TICK, null));

        $pdo->exec('UPDATE epics SET tails_rounds = '.Database::MAX_TAILS_ROUNDS." WHERE slug = 'demo'");
        $final = $prompt->forEpicRun('demo', Run::FINAL, null);

        self::assertStringContainsString('Tails rounds: '.Database::MAX_TAILS_ROUNDS.' of '.Database::MAX_TAILS_ROUNDS.' used', $final);
        self::assertStringContainsString('limit is exhausted', $final);
    }

    public function testEpicPromptWithoutLoadedFindingsOrOtherRunsHasNoReviewedCommit(): void
    {
        $this->saveCold();
        $rp = $this->runPrompt();

        self::assertStringNotContainsString('Reviewed commit', $rp->forEpicRun('demo', Run::REVIEW, 'ws#6'));
        self::assertStringNotContainsString('Reviewed commit', $rp->forEpicRun('demo', Run::TICK, null));
        self::assertSame([], $rp->epicReviewShas('demo', Run::REVIEW, 'ws#6'));
        self::assertSame([], $rp->epicReviewShas('demo', Run::TICK, null));
    }

    public function testEpicReviewShasAreKeyedByRepositoryAndNumber(): void
    {
        $this->saveCold();

        self::assertSame(['gpslab/ws#5' => self::HEAD], $this->runPrompt()->epicReviewShas('demo', Run::REVIEW, 'ws#5'));
    }

    public function testManualReviewRecordsOneHeadPerPr(): void
    {
        $gh = new FakeGitHub();
        $gh->heads['ws#1'] = str_repeat('1', 40);
        $gh->heads['other#2'] = str_repeat('2', 40);

        [$lines, , $shas] = $this->runPrompt($gh)->manualReviewFindings([['text' => 'review ws#1 and other#2']]);

        self::assertSame(['gpslab/ws#1' => str_repeat('1', 40), 'gpslab/other#2' => str_repeat('2', 40)], $shas);
        self::assertStringContainsString('- gpslab/ws#1: '.str_repeat('1', 40), implode("\n", $lines));
        self::assertStringContainsString('commit_id=<that SHA>', implode("\n", $lines));
    }

    public function testManualReviewWithoutRecognisedPrRecordsNothing(): void
    {
        [$lines, , $shas] = $this->runPrompt(new FakeGitHub())->manualReviewFindings([['text' => 'review please']]);

        self::assertSame([], $shas);
        self::assertStringNotContainsString('Reviewed commits', implode("\n", $lines));
    }

    public function testManualReviewOnGitlabSaysFindingsWereNotCollected(): void
    {
        // ws#1 would be recorded in $shas if the client were used: the branch is chosen by FORGE, not by a null client.
        $gh = new FakeGitHub();
        $gh->heads['ws#1'] = str_repeat('1', 40);
        foreach ([$gh, null] as $client) {
            [$lines, $ids, $shas] = $this->runPrompt($client, null, Config::FORGE_GITLAB)->manualReviewFindings([['text' => 'review !12 and ws#1']]);

            self::assertStringContainsString('NOT collected', implode("\n", $lines));
            self::assertStringContainsString('glab', implode("\n", $lines));
            self::assertNull($ids);
            self::assertSame([], $shas);
        }
    }

    public function testManualReviewWithNullClientOnGithubStaysSilent(): void
    {
        self::assertSame([[], null, []], $this->runPrompt(null)->manualReviewFindings([['text' => 'review ws#1']]));
    }

    /**
     * @param list<string>      $baseTasks plan tasks of the epic
     * @param list<string>|null $tasksBase tasks at the moment the breakage was confirmed (defaults to the plan tasks)
     */
    private function brokenMaster(Database $db, array $baseTasks, ?string $fixTask = null, ?array $tasksBase = null, string $repo = 'ws'): void
    {
        $tasks = [];
        foreach ($baseTasks as $t) {
            $tasks[] = ['task' => $t, 'after' => []];
        }
        $db->epicCreate('demo', 1, 0, $tasks, []);
        if ($fixTask !== null) {
            $db->epicApplySignal('demo', [['task' => $fixTask, 'after' => []]], null, false);
        }
        $db->epicMasterSet('demo', new MasterRecord($repo, 'sha-red', [], 0, MasterRecord::BROKEN, tasksBase: $tasksBase ?? $baseTasks));
    }

    public function testBrokenMasterWithExistingFixTaskNamesItAndDoesNotOrderReAdding(): void
    {
        $db = new Database($this->root);
        $this->brokenMaster($db, ['ws#1'], 'ws#9');

        $prompt = $this->runPrompt(null, $db)->forEpicRun('demo', Run::TICK, null);

        self::assertStringContainsString('Task ws#9 was added after the breakage', $prompt);
        self::assertStringNotContainsString('Create a fix task', $prompt);
    }

    public function testBrokenMasterWithoutFixTaskStillOrdersCreatingOne(): void
    {
        $db = new Database($this->root);
        $this->brokenMaster($db, ['ws#1']);

        $prompt = $this->runPrompt(null, $db)->forEpicRun('demo', Run::TICK, null);

        self::assertStringContainsString('Create a fix task', $prompt);
        self::assertStringContainsString('without "after"', $prompt);
    }

    public function testBrokenMasterWithFixTaskInAnotherRepositoryStillOrdersCreatingOne(): void
    {
        $db = new Database($this->root);
        // other#9 is an `added` task of a different repository than the broken `ws`.
        $this->brokenMaster($db, ['ws#1'], 'other#9');

        $prompt = $this->runPrompt(null, $db)->forEpicRun('demo', Run::TICK, null);

        self::assertStringContainsString('Create a fix task', $prompt);
        self::assertStringNotContainsString('was added after the breakage', $prompt);
    }

    public function testBrokenMasterWithFixTaskAlreadyInTheBaseStillOrdersCreatingOne(): void
    {
        $db = new Database($this->root);
        // ws#9 was added before the breakage was confirmed, so it is part of the base tasks.
        $this->brokenMaster($db, ['ws#1'], 'ws#9', ['ws#1', 'ws#9']);

        $prompt = $this->runPrompt(null, $db)->forEpicRun('demo', Run::TICK, null);

        self::assertStringContainsString('Create a fix task', $prompt);
        self::assertStringNotContainsString('was added after the breakage', $prompt);
    }

    public function testBrokenMasterWithOwnerQualifiedFixTaskOfANonDefaultOwnerNamesIt(): void
    {
        $db = new Database($this->root);
        $this->brokenMaster($db, ['acme/ws#1'], 'acme/ws#9', null, 'acme/ws');

        $prompt = $this->runPrompt(null, $db, Config::FORGE_GITHUB, new ForgeOwners(['gpslab', 'acme']))->forEpicRun('demo', Run::TICK, null);

        self::assertStringContainsString('Task acme/ws#9 was added after the breakage', $prompt);
        self::assertStringNotContainsString('Create a fix task', $prompt);
    }
}
