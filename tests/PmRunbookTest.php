<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\PmRunbook;
use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PmRunbook::class)]
final class PmRunbookTest extends TestCase
{
    private const array DEFAULT_SKIP = ['pm' => false, 'review' => true, 'tick' => false, 'final' => false, 'epic_review' => true];

    private string $dir;

    private string $instance;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pm-runbook-'.bin2hex(random_bytes(4));
        $this->instance = $this->dir.'/instance';
        mkdir($this->dir.'/prompts', 0777, true);
        mkdir($this->instance);
        foreach (glob(dirname(__DIR__).'/prompts/*.md.dist') ?: [] as $file) {
            copy($file, $this->dir.'/prompts/'.basename($file));
        }
        PromptFiles::useDirectory($this->dir.'/prompts');
    }

    protected function tearDown(): void
    {
        PromptFiles::useDirectory(null);
        foreach (glob($this->dir.'/{prompts,instance}/*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir.'/prompts');
        rmdir($this->instance);
        rmdir($this->dir);
    }

    private static function md(string $json): string
    {
        return "# Runbook\n\n```json\n{$json}\n```\n";
    }

    private const string VALID = '{"stages":[{"id":"a","agent":"x","required":true}],"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true}}';

    public function testShippedDistConfigIsValidAndHasTheDefaults(): void
    {
        $config = PmRunbook::config(null);

        self::assertSame([], $config['warnings']);
        self::assertSame(self::DEFAULT_SKIP, $config['skip_allowed']);
        self::assertSame(
            [
                ['id' => 'critique', 'agent' => 'critic', 'required' => true],
                ['id' => 'verify', 'agent' => 'verifier', 'required' => true],
                ['id' => 'edit', 'agent' => 'editor', 'required' => true],
                ['id' => 'scope', 'agent' => 'scope-check', 'required' => true],
            ],
            $config['stages'],
        );
    }

    public function testShippedRunbookIsInEnglishWithoutPlaceholders(): void
    {
        $text = PromptFiles::loadDist('pm_runbook');

        self::assertSame([], PromptFiles::placeholders($text));
        self::assertStringContainsString('run_in_background: false', $text);
        self::assertSame(1, preg_match('/Decision document: .*\nTarget repositories: /', $text));
    }

    public function testParsesValidBlock(): void
    {
        $parsed = PmRunbook::parse(self::md(self::VALID));

        self::assertSame([['id' => 'a', 'agent' => 'x', 'required' => true]], $parsed['stages'] ?? null);
        self::assertTrue($parsed['skip_allowed']['pm'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProvider(): iterable
    {
        yield 'no block' => ["# nothing\n"];
        yield 'bad json' => [self::md('{nope')];
        yield 'list' => [self::md('[1]')];
        yield 'unknown field' => [self::md('{"stages":[{"id":"a","agent":"x","required":true}],"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true},"x":1}')];
        yield 'no stages' => [self::md('{"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true}}')];
        yield 'empty stages' => [self::md('{"stages":[],"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true}}')];
        yield 'duplicate id' => [self::md('{"stages":[{"id":"a","agent":"x","required":true},{"id":"a","agent":"y","required":true}],"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true}}')];
        yield 'empty agent' => [self::md('{"stages":[{"id":"a","agent":"","required":true}],"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true}}')];
        yield 'required not bool' => [self::md('{"stages":[{"id":"a","agent":"x","required":"yes"}],"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true}}')];
        yield 'missing skip key' => [self::md('{"stages":[{"id":"a","agent":"x","required":true}],"skip_allowed":{"pm":true}}')];
        yield 'unknown skip key' => [self::md('{"stages":[{"id":"a","agent":"x","required":true}],"skip_allowed":{"pm":true,"review":true,"tick":true,"final":true,"epic_review":true,"arch":true}}')];
        yield 'skip not bool' => [self::md('{"stages":[{"id":"a","agent":"x","required":true}],"skip_allowed":{"pm":1,"review":true,"tick":true,"final":true,"epic_review":true}}')];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidBlock(string $markdown): void
    {
        $this->expectException(\RuntimeException::class);
        PmRunbook::parse($markdown);
    }

    public function testAdditionBlockReplacesStagesAndOverridesSkipByKey(): void
    {
        file_put_contents($this->instance.'/pm_runbook.md', "Extra rules.\n\n".self::md('{"stages":[{"id":"only","agent":"mine","required":false}],"skip_allowed":{"pm":true}}'));

        $config = PmRunbook::config($this->instance);

        self::assertSame([], $config['warnings']);
        self::assertSame([['id' => 'only', 'agent' => 'mine', 'required' => false]], $config['stages']);
        self::assertSame(['pm' => true] + self::DEFAULT_SKIP, $config['skip_allowed']);
        self::assertStringContainsString('Extra rules.', PmRunbook::text($this->instance));
    }

    public function testAdditionWithoutBlockKeepsBaseConfig(): void
    {
        file_put_contents($this->instance.'/pm_runbook.md', "Only text.\n");

        $config = PmRunbook::config($this->instance);

        self::assertSame([], $config['warnings']);
        self::assertSame(self::DEFAULT_SKIP, $config['skip_allowed']);
        self::assertCount(4, $config['stages']);
    }

    public function testMissingOrEmptyAdditionIsQuiet(): void
    {
        self::assertSame([], PmRunbook::config($this->instance)['warnings']);
        file_put_contents($this->instance.'/pm_runbook.md', '');
        self::assertSame([], PmRunbook::config($this->instance)['warnings']);
    }

    public function testInvalidAdditionConfigFallsBackToDistWithWarning(): void
    {
        file_put_contents($this->instance.'/pm_runbook.md', self::md('{"stages":[]}'));

        $config = PmRunbook::config($this->instance);

        self::assertSame(self::DEFAULT_SKIP, $config['skip_allowed']);
        self::assertCount(4, $config['stages']);
        self::assertCount(1, $config['warnings']);
        self::assertStringContainsString('instance/pm_runbook.md', $config['warnings'][0]);
    }

    public function testAdditionFallbackWarningReachesPmPromptOnly(): void
    {
        file_put_contents($this->instance.'/pm_runbook.md', self::md('{"stages":[]}'));
        $suffix = ' — the config without the addition was used.';

        $pm = SystemPrompt::build(SystemPrompt::MODE_PM, $this->instance)['warnings'];
        $arch = SystemPrompt::build(SystemPrompt::MODE_ARCH, $this->instance)['warnings'];

        self::assertStringContainsString($suffix, implode("\n", $pm));
        self::assertStringNotContainsString($suffix, implode("\n", $arch));
    }

    public function testNullAdditionFieldsAreInvalidNotIgnored(): void
    {
        foreach (['{"stages":null}', '{"skip_allowed":null}'] as $block) {
            file_put_contents($this->instance.'/pm_runbook.md', self::md($block));

            $config = PmRunbook::config($this->instance);

            self::assertSame(self::DEFAULT_SKIP, $config['skip_allowed'], $block);
            self::assertCount(1, $config['warnings'], $block);
        }
    }

    public function testLocalRunbookReplacesDistAndIsReportedAsContractOverride(): void
    {
        file_put_contents($this->dir.'/prompts/pm_runbook.md', "Local.\n\n".self::md(self::VALID));

        $config = PmRunbook::config(null);

        self::assertSame([['id' => 'a', 'agent' => 'x', 'required' => true]], $config['stages']);
        self::assertTrue($config['skip_allowed']['pm']);
        self::assertSame('Local.', explode("\n", PmRunbook::text(null))[0]);
        self::assertSame($this->dir.'/prompts/pm_runbook.md', PmRunbook::runbookPath());
        self::assertContains(
            'Contract prompt overridden locally: prompts/pm_runbook.md replaces prompts/pm_runbook.md.dist.',
            SystemPrompt::build(SystemPrompt::MODE_PM)['warnings'],
        );
    }

    public function testInvalidLocalRunbookConfigFallsBackToDistWithWarning(): void
    {
        file_put_contents($this->dir.'/prompts/pm_runbook.md', "Local without a block.\n");

        $config = PmRunbook::config(null);

        self::assertCount(4, $config['stages']);
        self::assertStringContainsString('prompts/pm_runbook.md has no ```json block', implode("\n", $config['warnings']));
        $suffix = ' — the config of prompts/pm_runbook.md.dist was used.';
        self::assertStringContainsString($suffix, implode("\n", SystemPrompt::build(SystemPrompt::MODE_PM)['warnings']));
        self::assertStringNotContainsString($suffix, implode("\n", SystemPrompt::build(SystemPrompt::MODE_ARCH)['warnings']));
    }

    public function testInvalidDistIsARunError(): void
    {
        file_put_contents($this->dir.'/prompts/pm_runbook.md.dist', "no config\n");

        $this->expectException(\RuntimeException::class);
        PmRunbook::config(null);
    }

    public function testLineHasAbsolutePathsAndAdditionOnlyWhenItExists(): void
    {
        $line = PmRunbook::line($this->instance);
        self::assertStringStartsWith('If this message starts or continues working out a task, follow the PM runbook: read '.$this->dir.'/prompts/pm_runbook.md.dist', $line);
        self::assertStringNotContainsString('instance addition', $line);
        self::assertStringNotContainsString("\n", $line);

        file_put_contents($this->instance.'/pm_runbook.md', 'ADDITION-TEXT');
        $line = PmRunbook::line($this->instance);
        self::assertStringContainsString('(and the instance addition '.$this->instance.'/pm_runbook.md)', $line);
        self::assertStringNotContainsString('ADDITION-TEXT', $line);
    }

    public function testRunKindMapping(): void
    {
        self::assertSame('pm', PmRunbook::skipKeyForMode(SystemPrompt::MODE_PM));
        self::assertSame('review', PmRunbook::skipKeyForMode(SystemPrompt::MODE_REVIEW));
        self::assertNull(PmRunbook::skipKeyForMode(SystemPrompt::MODE_ARCH));
        self::assertSame('tick', PmRunbook::skipKeyForEpicRun(Run::TICK));
        self::assertSame('final', PmRunbook::skipKeyForEpicRun(Run::FINAL));
        self::assertSame('epic_review', PmRunbook::skipKeyForEpicRun(Run::REVIEW));
    }

    public function testRolePersonaPointsToRunbookWithoutListingStages(): void
    {
        $role = PromptFiles::loadDist('role_pm');
        self::assertStringStartsWith('Role — you run an internal PM loop', $role);
        self::assertStringContainsString('PM runbook', $role);
        foreach (['critic', 'verifier', 'editor', 'scope-check'] as $agent) {
            self::assertStringNotContainsString($agent, $role);
        }
    }
}
