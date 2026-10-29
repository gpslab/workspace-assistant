<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\ReviewStatusHook;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptFiles::class)]
#[CoversClass(SystemPrompt::class)]
#[CoversClass(ReviewStatusHook::class)]
#[CoversClass(Run::class)]
final class PromptFilesTest extends TestCase
{
    /** Every text of the closed list: name => whether it is part of the contract. */
    private const array NAMES = [
        'common' => true,
        'persona_common' => false,
        'common_limits' => true,
        'role_arch' => false,
        'role_review' => false,
        'role_pm' => false,
        'contract_review' => true,
        'language_line' => true,
        'session_update' => true,
        'review_status_reason' => true,
        'review_status_reason_no_verdict' => true,
        'review_status_reason_ids' => true,
        'review_status_reason_steps' => true,
        'compact_summarize_prompt' => true,
        'compact_reseed_prefix' => true,
        'compact_reseed_suffix' => true,
        'default_file_prompt' => false,
        'epic_runbook' => true,
        'pm_runbook' => true,
        'forge_gitlab' => false,
    ];

    /** sha256 of the texts as they were assembled from the PHP constants, before they moved to files. */
    private const array GOLDEN = [
        'prompt_arch' => '376a2c7b9b26425b7115b8e0d093c5fc27fa31aa690f01c6ee5c7c684c9944ab',
        'prompt_arch_dir' => 'f0418868048d57fb94128286aa81abdd13de9eb83db3e84398d87d502b6a5aa5',
        'prompt_review' => '2da2e8ce2a55352509b2da4da3dbc2543e69d8939ab95e4c4dcd03ab0c5acbde',
        'prompt_review_dir' => 'cfdd44a2913ad8cbd47b15858208a63c48abf9a19283a48f36d6718a8689f64c',
        'prompt_pm' => '1b2058e33a61a35221f255e8a6774df23e0f8671a4c3442613c4e12ce2c21c36',
        'prompt_pm_dir' => 'f833469e528c736dee939a8f094e5eee8c0b2612c4018919e3ea26144af0aaa4',
        'lang_en' => '041abac3c59dfa51c46afb98e6bf7c8d54c2b012b9f836d79e4057d15da4f65c',
        'compact_summarize' => 'bd3113eb06a70e558bed972cf8d3da85edafa6aaafc9ad57756f8acd3575e327',
        'compact_reseed_prefix' => 'a5de86dc6f44a6a53adb1d450bfb21d3d5a6b9ef6460d126ced385eb6e1abd90',
        'compact_reseed_suffix' => '40b0b818c6c0c970aec56a8b43c493166d550877244bee8690baeeba4b31ffa9',
        'default_file_prompt' => '1b73ee0a4e07d331bd7d9ad5a815dc374ef56ccf7f002c23540953b0dbe5a7a6',
        'r_noverdict' => '215bfeea0e92e3fab88c8f856873a7331b55e6f80a12bad4292318b9968b0514',
        'r_green_none' => '5667160d9b52df76b2e10155a7e755d02c7dca8d3efe147cece671ac790ffcdc',
        'r_red_none' => '70ead4b7ce9bd5ded46d1873beea4c7ed08f5375f1939fd85d2ddbeff26a50e8',
        'r_green_opp' => '70cbebf133de5ec57fc73f8d5fb843ba0110c6c6dcde173df6b82de9e3ef2e1d',
        'r_red_opp' => 'e1f196355d8cd9a953a1d09ba8179b044e384485313abf95dfa7609300a62bb3',
        'r_ids' => '1be918b3e0299b974e38b24719ffc4f84d5c182fb34ca5127958e4c6f1a567a9',
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/promptfiles-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        PromptFiles::useDirectory(null);
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @chmod($file, 0644);
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** A private copy of the shipped `.dist` files, optionally without one of them. */
    private function isolate(?string $without = null): void
    {
        foreach (self::NAMES as $name => $_) {
            if ($name !== $without) {
                copy(PromptFiles::distPath($name), $this->dir.'/'.$name.'.md.dist');
            }
        }
        PromptFiles::useDirectory($this->dir);
    }

    private static function sha(string $text): string
    {
        return hash('sha256', $text);
    }

    // --- byte-for-byte equality with the former constants ---

    public function testAssembledSystemPromptsAreUnchanged(): void
    {
        $this->isolate();
        $fixture = __DIR__.'/fixtures/prompts';
        foreach (SystemPrompt::MODES as $mode) {
            self::assertSame(self::GOLDEN['prompt_'.$mode], self::sha(SystemPrompt::forMode($mode)), $mode);
            self::assertSame(self::GOLDEN['prompt_'.$mode.'_dir'], self::sha(SystemPrompt::forMode($mode, $fixture)), $mode);
        }
        self::assertSame(self::GOLDEN['lang_en'], self::sha(SystemPrompt::languageLine('English')));
    }

    public function testCompactAndFilePromptsAreUnchanged(): void
    {
        $this->isolate();
        self::assertSame(self::GOLDEN['compact_summarize'], self::sha(PromptFiles::load('compact_summarize_prompt')));
        self::assertSame(self::GOLDEN['compact_reseed_prefix'], self::sha(PromptFiles::load('compact_reseed_prefix')."\n"));
        self::assertSame(self::GOLDEN['compact_reseed_suffix'], self::sha("\n\n".PromptFiles::load('compact_reseed_suffix')));
        self::assertSame(self::GOLDEN['default_file_prompt'], self::sha(PromptFiles::load('default_file_prompt')));
        self::assertSame('Look at the attached file(s) and discuss them with me.', PromptFiles::load('default_file_prompt'));
    }

    public function testReviewReasonsAreUnchanged(): void
    {
        $this->isolate();
        $user = (string) json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => 'q']]);
        $bash = static fn (string $command): string => (string) json_encode(['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => 'a', 'name' => 'Bash', 'input' => ['command' => $command]],
        ]]]);
        self::assertSame(self::GOLDEN['r_noverdict'], self::reasonHash('x', [$user]));
        self::assertSame(self::GOLDEN['r_green_none'], self::reasonHash('🟢 ok', [$user]));
        self::assertSame(self::GOLDEN['r_red_none'], self::reasonHash('🔴 no', [$user]));
        self::assertSame(self::GOLDEN['r_green_opp'], self::reasonHash('🟢 ok', [$user, $bash('gh pr review 1 --request-changes')]));
        self::assertSame(self::GOLDEN['r_red_opp'], self::reasonHash('🔴 no', [$user, $bash('gh pr review 1 --approve')]));
        self::assertSame(self::GOLDEN['r_ids'], self::reasonHash('🟢 ok', [$user, $bash('gh pr review 1 --approve --body "C1"')], ['C1', 'C2', 'C3']));
    }

    /**
     * @param list<string>      $lines
     * @param list<string>|null $ids
     */
    private static function reasonHash(string $reply, array $lines, ?array $ids = null): string
    {
        return self::sha(ReviewStatusHook::reason(['last_assistant_message' => $reply], $lines, $ids));
    }

    // --- the shipped files ---

    /** @return iterable<string, array{string}> */
    public static function nameProvider(): iterable
    {
        foreach (array_keys(self::NAMES) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('nameProvider')]
    public function testEveryNameHasDist(string $name): void
    {
        $this->isolate();
        self::assertFileExists(dirname(__DIR__).'/prompts/'.$name.'.md.dist');
        self::assertNotSame('', PromptFiles::load($name));
    }

    public function testNoStrayDistFiles(): void
    {
        $found = array_map(static fn (string $f): string => basename($f, '.md.dist'), glob(dirname(__DIR__).'/prompts/*.md.dist') ?: []);
        $expected = array_keys(self::NAMES);
        sort($found);
        sort($expected);
        self::assertSame($expected, $found);
    }

    public function testContractListMatchesTable(): void
    {
        $contract = array_keys(array_filter(self::NAMES));
        $actual = PromptFiles::CONTRACT;
        sort($contract);
        sort($actual);
        self::assertSame($contract, $actual);
    }

    public function testLocalFilesAreGitignoredAndDistIsNot(): void
    {
        $root = dirname(__DIR__);
        self::assertStringContainsString("\n/prompts/*.md\n", (string) file_get_contents($root.'/.gitignore'));
        self::assertStringContainsString("\n/prompts/agents/*.md\n", (string) file_get_contents($root.'/.gitignore'));
        self::assertFileDoesNotExist($root.'/resources/epic-runbook.md');
    }

    public function testEveryDistPlaceholderIsFilled(): void
    {
        $this->isolate();
        $values = [
            'language_line' => ['language' => 'English'],
            'session_update' => ['prompt' => 'text'],
            'review_status_reason' => ['verdict' => '🟢', 'state' => 'Approve', 'problem' => 'p'],
            'review_status_reason_ids' => ['ids' => 'C1'],
        ];
        foreach (array_keys(self::NAMES) as $name) {
            $placeholders = PromptFiles::placeholders(PromptFiles::load($name));
            $keys = array_keys($values[$name] ?? []);
            sort($keys);
            self::assertSame($placeholders, $keys, $name);

            $warnings = [];
            $text = PromptFiles::render($name, $values[$name] ?? [], $warnings);
            self::assertSame([], $warnings, $name);
            self::assertSame([], PromptFiles::placeholders($text), $name);
        }
    }

    // --- loader ---

    public function testMissingDistFailsWithFileName(): void
    {
        $this->isolate('role_pm');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prompts/role_pm.md.dist');
        SystemPrompt::forMode(SystemPrompt::MODE_PM);
    }

    public function testLocalFileBeatsDist(): void
    {
        $this->isolate();
        file_put_contents($this->dir.'/role_pm.md', 'LOCAL-PM');

        self::assertSame('LOCAL-PM', PromptFiles::load('role_pm'));
        self::assertStringContainsString('LOCAL-PM', SystemPrompt::forMode(SystemPrompt::MODE_PM));
    }

    public function testCrlfBecomesLfAndOnlyOneTrailingNewlineIsRemoved(): void
    {
        $this->isolate();
        file_put_contents($this->dir.'/role_pm.md', "a\r\n\r\nb\r\n\r\n");
        self::assertSame("a\n\nb\n", PromptFiles::load('role_pm'));

        file_put_contents($this->dir.'/role_pm.md', "  a  \n");
        self::assertSame('  a  ', PromptFiles::load('role_pm'));
    }

    public function testEditBetweenCallsIsVisible(): void
    {
        $this->isolate();
        file_put_contents($this->dir.'/role_pm.md', 'first');
        self::assertSame('first', PromptFiles::load('role_pm'));

        file_put_contents($this->dir.'/role_pm.md', 'second');
        self::assertSame('second', PromptFiles::load('role_pm'));

        unlink($this->dir.'/role_pm.md');
        self::assertStringStartsWith('Role — you run an internal PM loop', PromptFiles::load('role_pm'));
    }

    public function testEmptyLocalFileFallsBackToDistWithWarning(): void
    {
        $this->isolate();
        file_put_contents($this->dir.'/role_pm.md', " \n");

        $warnings = [];
        $text = PromptFiles::load('role_pm', $warnings);

        self::assertStringStartsWith('Role — you run an internal PM loop', $text);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('prompts/role_pm.md is empty or unreadable', $warnings[0]);
        self::assertStringContainsString('prompts/role_pm.md.dist was used', $warnings[0]);
    }

    public function testUnreadableLocalFileFallsBackToDistWithWarning(): void
    {
        $this->isolate();
        file_put_contents($this->dir.'/role_pm.md', 'secret');
        chmod($this->dir.'/role_pm.md', 0000);
        if (is_readable($this->dir.'/role_pm.md')) {
            self::markTestSkipped('Running as a user that can read any file.');
        }

        $warnings = [];
        $text = PromptFiles::load('role_pm', $warnings);

        self::assertStringStartsWith('Role — you run an internal PM loop', $text);
        self::assertCount(1, $warnings);
    }

    public function testPlaceholderSetMismatchWarns(): void
    {
        $this->isolate();
        file_put_contents($this->dir.'/language_line.md', 'Reply in {lang}.');

        $warnings = [];
        $text = SystemPrompt::languageLine('English', $warnings);

        self::assertSame('Reply in {lang}.', $text);
        self::assertContains('Prompt file prompts/language_line.md has placeholders that differ from prompts/language_line.md.dist.', $warnings);
        self::assertContains('Prompt file prompts/language_line.md has unfilled placeholders: {lang}.', $warnings);
    }

    public function testMatchingPlaceholdersDoNotWarn(): void
    {
        $this->isolate();
        file_put_contents($this->dir.'/language_line.md', 'Reply in {language}.');

        $warnings = [];
        self::assertSame('Reply in Russian.', SystemPrompt::languageLine('Russian', $warnings));
        self::assertSame([], $warnings);
    }

    public function testReasonPlaceholdersAreNamed(): void
    {
        $this->isolate();
        self::assertSame(['problem', 'state', 'verdict'], PromptFiles::placeholders(PromptFiles::load('review_status_reason')));
        self::assertSame(['ids'], PromptFiles::placeholders(PromptFiles::load('review_status_reason_ids')));
        self::assertSame(['language'], PromptFiles::placeholders(PromptFiles::load('language_line')));
    }

    // --- persona chain ---

    public function testPersonaChain(): void
    {
        $this->isolate();
        $promptsDir = $this->dir.'/instance';
        mkdir($promptsDir);
        try {
            // dist
            self::assertStringContainsString('Role — you run an internal PM loop', SystemPrompt::forMode(SystemPrompt::MODE_PM, $promptsDir));

            // local prompts/ file
            file_put_contents($this->dir.'/role_pm.md', 'LOCAL-ROLE');
            file_put_contents($this->dir.'/persona_common.md', 'LOCAL-COMMON');
            $text = SystemPrompt::forMode(SystemPrompt::MODE_PM, $promptsDir);
            self::assertStringContainsString("\n\nLOCAL-COMMON\n\nLOCAL-ROLE", $text);

            // PROMPTS_DIR wins and is trimmed on both sides
            file_put_contents($promptsDir.'/pm.md', "\n  INSTANCE-ROLE \n\n");
            file_put_contents($promptsDir.'/common.md', "\tINSTANCE-COMMON\n");
            $built = SystemPrompt::build(SystemPrompt::MODE_PM, $promptsDir);
            self::assertStringEndsWith("\n\nINSTANCE-COMMON\n\nINSTANCE-ROLE", $built['text']);
            self::assertSame([], $built['warnings']);
        } finally {
            @unlink($promptsDir.'/pm.md');
            @unlink($promptsDir.'/common.md');
            rmdir($promptsDir);
        }
    }

    public function testMissingPromptsDirFileWarningNamesActualSource(): void
    {
        $this->isolate();
        $promptsDir = $this->dir.'/instance';
        mkdir($promptsDir);
        try {
            $built = SystemPrompt::build(SystemPrompt::MODE_PM, $promptsDir, 'ctx/prompts');
            self::assertSame([
                'Prompt file ctx/prompts/common.md is missing, empty or unreadable — prompts/persona_common.md.dist was used.',
                'Prompt file ctx/prompts/pm.md is missing, empty or unreadable — prompts/role_pm.md.dist was used.',
            ], $built['warnings']);

            file_put_contents($this->dir.'/role_pm.md', 'LOCAL-ROLE');
            $built = SystemPrompt::build(SystemPrompt::MODE_PM, $promptsDir, 'ctx/prompts');
            self::assertSame('Prompt file ctx/prompts/pm.md is missing, empty or unreadable — prompts/role_pm.md was used.', $built['warnings'][1]);
            foreach ($built['warnings'] as $warning) {
                self::assertStringNotContainsString('built-in', $warning);
            }
        } finally {
            rmdir($promptsDir);
        }
    }

    public function testNoBuiltInWordingLeftInSources(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/src/SystemPrompt.php');
        self::assertDoesNotMatchRegularExpression('/built-in .* text was used/', $source);
    }

    // --- contract overrides are always reported ---

    public function testContractOverrideWarnsInEveryModeWithFileName(): void
    {
        $this->isolate();
        foreach (array_keys(array_filter(self::NAMES)) as $name) {
            file_put_contents($this->dir.'/'.$name.'.md', 'x');
            foreach (SystemPrompt::MODES as $mode) {
                $joined = implode("\n", SystemPrompt::build($mode)['warnings']);
                self::assertStringContainsString('prompts/'.$name.'.md ', $joined, $name.' '.$mode);
            }
            unlink($this->dir.'/'.$name.'.md');
        }
        self::assertSame([], SystemPrompt::build(SystemPrompt::MODE_REVIEW)['warnings']);
    }

    public function testPersonaOverrideDoesNotWarn(): void
    {
        $this->isolate();
        foreach (['persona_common', 'role_arch', 'role_review', 'role_pm', 'default_file_prompt'] as $name) {
            file_put_contents($this->dir.'/'.$name.'.md', 'x');
        }

        foreach (SystemPrompt::MODES as $mode) {
            self::assertSame([], SystemPrompt::build($mode)['warnings'], $mode);
        }
    }

    // --- epic runbook ---

    public function testRunbookPathPrefersLocalFile(): void
    {
        $this->isolate();
        self::assertSame($this->dir.'/epic_runbook.md.dist', Run::runbookPath());

        file_put_contents($this->dir.'/epic_runbook.md', 'local');
        self::assertSame($this->dir.'/epic_runbook.md', Run::runbookPath());
    }

    // --- Stop hook ---

    public function testReasonFallsBackToHardcodedTextWhenLoadingFails(): void
    {
        $this->isolate('review_status_reason');
        $lines = [(string) json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => 'q']])];
        $errors = [];

        $reason = ReviewStatusHook::reasonOrFallback(['last_assistant_message' => '🟢 ok'], $lines, null, static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        self::assertSame(ReviewStatusHook::FALLBACK_REASON, $reason);
        self::assertCount(1, $errors);
        self::assertStringContainsString('review_status_reason.md.dist', $errors[0]);
    }

    public function testHookScriptStillBlocksWhenReasonTextsAreMissing(): void
    {
        $transcript = $this->dir.'/transcript.jsonl';
        file_put_contents($transcript, implode("\n", [
            (string) json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => 'review pr']]),
            (string) json_encode(['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'Bash', 'input' => ['command' => 'gh pr view 1']],
            ]]]),
        ])."\n");
        $empty = $this->dir.'/empty';
        mkdir($empty);
        file_put_contents($this->dir.'/prepend.php', '<?php require '.var_export(dirname(__DIR__).'/bootstrap.php', true).'; \GpsLab\WorkspaceAssistant\PromptFiles::useDirectory('.var_export($empty, true).');');

        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file='.$this->dir.'/prepend.php', dirname(__DIR__).'/bin/review-stop-hook.php', $this->dir],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], (string) json_encode(['session_id' => 's1', 'transcript_path' => $transcript, 'last_assistant_message' => '🟢 ok']));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        rmdir($empty);
        @unlink($this->dir.'/prepend.php');
        @unlink($transcript);
        @unlink($this->dir.'/review-hook.log');

        self::assertSame(0, $code);
        self::assertSame(['decision' => 'block', 'reason' => ReviewStatusHook::FALLBACK_REASON], json_decode($stdout, true));
    }
}
