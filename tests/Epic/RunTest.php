<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Epic\MasterRecord;
use GpsLab\WorkspaceAssistant\Epic\Run;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Run::class)]
final class RunTest extends TestCase
{
    private const array RUNS = [['id' => 11, 'url' => 'https://github.com/anime-db/a/actions/runs/11', 'workflow' => 'CI']];

    public function testTickPromptNamesRepoShaAndFailedRunOfBrokenMaster(): void
    {
        $prompt = Run::prompt('demo', Run::TICK, 'p', 'j', 's', 'f', null, null, [
            ['repo' => 'a', 'sha' => 'abc123', 'kind' => MasterRecord::BROKEN, 'runs' => self::RUNS],
        ]);
        self::assertStringContainsString('Master CI is broken: repository a, commit abc123', $prompt);
        self::assertStringContainsString('https://github.com/anime-db/a/actions/runs/11 (CI)', $prompt);
        self::assertStringContainsString('without "after"', $prompt);
    }

    public function testTickPromptNamesRepoShaAndFailedRunOfFlakyMaster(): void
    {
        $prompt = Run::prompt('demo', Run::TICK, 'p', 'j', 's', 'f', null, null, [
            ['repo' => 'a', 'sha' => 'abc123', 'kind' => MasterRecord::FLAKY, 'runs' => self::RUNS],
        ]);
        self::assertStringContainsString('Flaky test: repository a, commit abc123', $prompt);
        self::assertStringContainsString('https://github.com/anime-db/a/actions/runs/11', $prompt);
    }

    public function testPromptWithoutMasterEventsHasNoMasterSection(): void
    {
        self::assertStringNotContainsString('Master CI', Run::prompt('demo', Run::TICK, 'p', 'j', 's', 'f'));
    }

    public function testPromptPointsAtExistingRunbookAndOutbox(): void
    {
        $prompt = Run::prompt('demo', Run::FINAL, 'p', 'j', 's', 'f', null, null, [], '/var/state/outbox');

        self::assertStringStartsWith('This is an isolated epic run', $prompt);
        self::assertStringContainsString(Run::runbookPath(), $prompt);
        self::assertStringStartsWith('/', Run::runbookPath());
        self::assertFileExists(Run::runbookPath());
        self::assertStringContainsString('Outbox directory: /var/state/outbox', $prompt);
    }

    public function testPromptHasNoInstancePipelineNames(): void
    {
        $prompt = Run::prompt('demo', Run::REVIEW, 'p', 'j', 's', 'f', 'a#5', [['id' => 'C1', 'source' => 'x', 'path' => null, 'line' => null, 'body' => 'b']])
            .Run::prompt('demo', Run::TICK, 'p', 'j', 's', 'f', null, null, [
                ['repo' => 'a', 'sha' => 'abc123', 'kind' => MasterRecord::BROKEN, 'runs' => self::RUNS],
            ]);

        foreach (['epic-autopilot', '`verifier`', '`editor`', '`scope-guard`'] as $name) {
            self::assertStringNotContainsString($name, $prompt);
        }
    }

    public function testSourceHasNoInstancePipelineNames(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Epic/Run.php');
        foreach (['epic-autopilot', '`verifier`', '`editor`', '`scope-guard`'] as $name) {
            self::assertStringNotContainsString($name, $source);
        }
    }

    public function testAdditionFollowsRunbookAndIsAbsentWithoutIt(): void
    {
        $prompt = Run::prompt('demo', Run::TICK, 'p', 'j', 's', 'f', null, null, [], '', 'INSTANCE-ADDITION');

        self::assertGreaterThan(strpos($prompt, Run::runbookPath()), strpos($prompt, 'INSTANCE-ADDITION'));
        self::assertStringNotContainsString('INSTANCE-ADDITION', Run::prompt('demo', Run::TICK, 'p', 'j', 's', 'f'));
    }

    public function testAdditionIsReadFromPromptsDirAndMissingFileIsQuiet(): void
    {
        $dir = sys_get_temp_dir().'/epic-addition-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            self::assertSame('', Run::addition(null));
            self::assertSame('', Run::addition(''));
            self::assertSame('', Run::addition($dir));
            file_put_contents($dir.'/epic.md', "  extra rules\n");
            self::assertSame('extra rules', Run::addition($dir));
        } finally {
            @unlink($dir.'/epic.md');
            rmdir($dir);
        }
    }

    public function testRunbookFileIsUnchanged(): void
    {
        self::assertSame('56c8bff2511e5edc7ae881f9a74cff2ab5ac40443e35b93a3680d0d4d28aee32', hash_file('sha256', Run::runbookPath()));
    }

    public function testPmRunbookLineIsInEveryEpicRunPrompt(): void
    {
        foreach ([Run::TICK, Run::FINAL, Run::REVIEW] as $run) {
            $prompt = Run::prompt('demo', $run, 'p', 'j', 's', 'f', null, null, [], '', '', 'PM-RUNBOOK-LINE');
            self::assertStringContainsString("\nPM-RUNBOOK-LINE\n", $prompt, $run);
            self::assertStringNotContainsString('PM-RUNBOOK-LINE', Run::prompt('demo', $run, 'p', 'j', 's', 'f'), $run);
        }
    }
}
