<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Epic;

use GpsLab\WorkspaceAssistant\Epic\ColdFindings;
use GpsLab\WorkspaceAssistant\Epic\Run;
use GpsLab\WorkspaceAssistant\Epic\Signal;
use GpsLab\WorkspaceAssistant\GithubOwners;
use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColdFindings::class)]
#[CoversClass(Signal::class)]
#[CoversClass(Run::class)]
final class ColdFindingsTest extends TestCase
{
    /**
     * @return list<array{id: string, source: string, path: ?string, line: ?int, body: string}>
     */
    private static function findings(): array
    {
        return [
            ['id' => 'C1', 'source' => 'inline', 'path' => 'src/A.php', 'line' => 12, 'body' => 'null deref'],
            ['id' => 'C2', 'source' => 'review', 'path' => null, 'line' => null, 'body' => "summary\nsecond line"],
        ];
    }

    private static function signal(string $verdict, string $cold): Signal
    {
        return Signal::parse('{"verdict":"'.$verdict.'","cold_findings":'.$cold.'}', new GithubOwners(['anime-db']));
    }

    public function testFullCoverageHasNoProblems(): void
    {
        $signal = self::signal('approve', '[{"id":"C1","verdict":"fixed","where":"src/A.php:14"},{"id":"C2","verdict":"refuted","where":"verifier"}]');
        self::assertSame(['missing' => [], 'open' => [], 'problems' => []], ColdFindings::check($signal, self::findings()));
    }

    public function testMissingIdIsAProblem(): void
    {
        $signal = self::signal('request_changes', '[{"id":"C1","verdict":"open","where":""}]');
        $result = ColdFindings::check($signal, self::findings());
        self::assertSame(['C2'], $result['missing']);
        self::assertNotSame([], $result['problems']);
    }

    public function testApproveWithOpenIsAProblem(): void
    {
        $signal = self::signal('approve', '[{"id":"C1","verdict":"open","where":""},{"id":"C2","verdict":"fixed","where":"x:1"}]');
        $result = ColdFindings::check($signal, self::findings());
        self::assertSame(['C1'], $result['open']);
        self::assertCount(1, $result['problems']);
        self::assertStringContainsString('approve', $result['problems'][0]);
    }

    public function testProblemsAreEnglishByDefaultAndFollowTheLocale(): void
    {
        // Deliberately Cyrillic: the ru expectations check that the passed locale is used
        $signal = Signal::parse('{}', new GithubOwners(['anime-db']));
        $en = ColdFindings::check($signal, self::findings())['problems'];
        self::assertSame(['the signal has no verdict (approve | request_changes)', 'no verdict on the cold reviewer findings: C1, C2'], $en);
        self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', implode(' ', $en));

        $ru = ColdFindings::check($signal, self::findings(), new Locale('ru'))['problems'];
        self::assertSame(['в сигнале нет verdict (approve | request_changes)', 'нет вердикта по находкам холодного ревьюера: C1, C2'], $ru);

        $open = self::signal('approve', '[{"id":"C1","verdict":"open","where":""},{"id":"C2","verdict":"fixed","where":"x:1"}]');
        self::assertSame(['verdict=approve with findings in status open: C1'], ColdFindings::check($open, self::findings())['problems']);
        self::assertSame(['verdict=approve при находках со статусом open: C1'], ColdFindings::check($open, self::findings(), new Locale('ru'))['problems']);
    }

    public function testRequestChangesWithOpenIsFine(): void
    {
        $signal = self::signal('request_changes', '[{"id":"C1","verdict":"open","where":""},{"id":"C2","verdict":"fixed","where":"x:1"}]');
        self::assertSame([], ColdFindings::check($signal, self::findings())['problems']);
    }

    public function testMissingVerdictIsAProblemEvenWithoutFindings(): void
    {
        $result = ColdFindings::check(Signal::parse('{}', new GithubOwners(['anime-db'])), []);
        self::assertCount(1, $result['problems']);
        self::assertStringContainsString('verdict', $result['problems'][0]);
        self::assertSame([], ColdFindings::check(self::signal('approve', '[]'), [])['problems']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSignals(): iterable
    {
        yield 'verdict' => ['{"verdict":"lgtm"}'];
        yield 'cold_findings is not a list' => ['{"cold_findings":{"id":"C1"}}'];
        yield 'bad id' => ['{"cold_findings":[{"id":"x","verdict":"open"}]}'];
        yield 'bad finding verdict' => ['{"cold_findings":[{"id":"C1","verdict":"maybe"}]}'];
        yield 'fixed without where' => ['{"cold_findings":[{"id":"C1","verdict":"fixed"}]}'];
    }

    #[DataProvider('badSignals')]
    public function testInvalidSignalFieldsAreRejected(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signal::parse($json, new GithubOwners(['anime-db']));
    }

    public function testSignalFieldsAreParsed(): void
    {
        $signal = self::signal('request_changes', '[{"id":"C1","verdict":"open"}]');
        self::assertSame('request_changes', $signal->verdict);
        self::assertSame([['id' => 'C1', 'verdict' => 'open', 'where' => '']], $signal->coldFindings);
        self::assertNull(Signal::parse('{}', new GithubOwners(['anime-db']))->verdict);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $dir = sys_get_temp_dir().'/tgcf_'.uniqid('', true);
        $path = ColdFindings::path($dir, 'demo', 'a#5');
        self::assertSame($dir.'/epics/demo/cold-a-5.json', $path);
        self::assertNull(ColdFindings::load($path));

        $data = ['task' => 'a#1', 'pr' => 'a#5', 'head' => 'h1', 'findings' => self::findings()];
        ColdFindings::save($path, $data);
        self::assertSame($data, ColdFindings::load($path));
        exec('rm -rf '.escapeshellarg($dir));
    }

    /**
     * @param list<array{id: string, source: string, path: ?string, line: ?int, body: string}>|null $findings
     */
    private static function reviewPrompt(?array $findings): string
    {
        return Run::prompt('demo', Run::REVIEW, 'plan.md', 'journal.md', 'signal.json', 'fail.log', 'a#5', $findings);
    }

    public function testReviewPromptListsFindingsWithIdsAndDemandsReport(): void
    {
        $prompt = self::reviewPrompt(self::findings());
        self::assertStringContainsString('[C1] inline (src/A.php:12):', $prompt);
        self::assertStringContainsString('null deref', $prompt);
        self::assertStringContainsString('[C2] review:', $prompt);
        self::assertStringContainsString('Report every id in "cold_findings"', $prompt);
        self::assertStringContainsString('"verdict"', $prompt);
    }

    public function testReviewPromptStatesThereAreNoFindings(): void
    {
        self::assertStringContainsString('findings: none. Report "cold_findings": [] in the signal.', self::reviewPrompt([]));
    }

    public function testNonReviewPromptHasNoFindingsBlock(): void
    {
        $prompt = Run::prompt('demo', Run::TICK, 'p', 'j', 's', 'f', null, self::findings());
        self::assertStringNotContainsString('[C1]', $prompt);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, list<string>}>
     */
    public static function prProvider(): iterable
    {
        yield 'url' => [[['text' => 'check https://github.com/anime-db/desktop/pull/12 please']], ['desktop#12']];
        yield 'short' => [[['text' => 'check folder-info#7']], ['folder-info#7']];
        yield 'any part and caption' => [
            [['text' => 'here', 'files' => []], ['text' => null], ['text' => "file caption\nfolder-info#7, desktop#3"]],
            ['folder-info#7', 'desktop#3'],
        ];
        yield 'dedup' => [[['text' => 'a#1 https://github.com/anime-db/a/pull/1'], ['text' => 'a#1']], ['a#1']];
        yield 'bare number' => [[['text' => 'check #45']], []];
        yield 'second owner url' => [[['text' => 'https://github.com/gpslab/workspace-assistant/pull/5']], ['gpslab/workspace-assistant#5']];
        yield 'second owner short' => [[['text' => 'gpslab/workspace-assistant#5']], ['gpslab/workspace-assistant#5']];
        yield 'default owner full' => [[['text' => 'anime-db/desktop#5 https://github.com/ANIME-DB/desktop/pull/5']], ['desktop#5']];
        yield 'foreign owner url' => [[['text' => 'https://github.com/other/desktop/pull/12']], []];
        yield 'foreign owner short' => [[['text' => 'other/desktop#12']], []];
        yield 'no text' => [[['text' => '', 'files' => []]], []];
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @param list<string>               $expected
     */
    #[DataProvider('prProvider')]
    public function testExtractPrs(array $parts, array $expected): void
    {
        self::assertSame($expected, ColdFindings::extractPrs($parts, new GithubOwners(['anime-db', 'gpslab'])));
    }

    public function testExtractPrsRequiresOwners(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GithubOwners::NOT_SET);

        ColdFindings::extractPrs([['text' => 'a#1']], new GithubOwners());
    }

    public function testPathKeepsOwnerOutOfDirectories(): void
    {
        self::assertSame('/s/epics/x/cold-gpslab@a-1.json', ColdFindings::path('/s', 'x', 'gpslab/a#1'));
        self::assertNotSame(ColdFindings::path('/s', 'x', 'gpslab/lib#3'), ColdFindings::path('/s', 'x', 'gpslab__lib#3'));
        self::assertSame('/s/epics/x/cold-a-1.json', ColdFindings::path('/s', 'x', 'a#1'));
    }

    private static function owners(): GithubOwners
    {
        return new GithubOwners(['anime-db', 'gpslab']);
    }

    public function testExtractBareNumbers(): void
    {
        $parts = [
            ['text' => 'Check PR #47, PR #48 and a#49; a#47 too'],
            ['text' => 'also #47 #50 #51 https://github.com/anime-db/x/pull/12#issuecomment-1'],
            ['text' => 'https://github.com/anime-db/x/pull/50 and #50'],
        ];

        self::assertSame([48, 47, 50], ColdFindings::extractBareNumbers($parts, 3, self::owners()));
        self::assertSame([], ColdFindings::extractBareNumbers($parts, 0, self::owners()));
    }

    public function testExtractBareNumbersDropsZeroAndOversized(): void
    {
        $parts = [['text' => '#0 #00 #3000000000 #99999999999999999999 #12345678 #9999999 #47']];

        self::assertSame([9999999, 47], ColdFindings::extractBareNumbers($parts, 5, self::owners()));
    }

    public function testQualifyPrefixesIdsOnlyForSeveralPrs(): void
    {
        self::assertSame(['C1', 'C2'], array_column(ColdFindings::qualify(['a#1' => self::findings()]), 'id'));
        self::assertSame(
            ['a#1:C1', 'a#1:C2', 'b#2:C1', 'b#2:C2'],
            array_column(ColdFindings::qualify(['a#1' => self::findings(), 'b#2' => self::findings()]), 'id'),
        );
    }

    public function testManualPromptListsFindingsAndDemandsLinePerFinding(): void
    {
        $text = implode("\n", ColdFindings::manualPromptLines(self::findings()));
        self::assertStringContainsString('[C1] inline (src/A.php:12):', $text);
        self::assertStringContainsString('one line per finding', $text);
        self::assertStringContainsString('fixed', $text);
        self::assertStringContainsString('-F body=@file', $text);
    }

    public function testManualPromptStatesThereAreNoFindings(): void
    {
        self::assertStringContainsString('findings collected by the bot: none.', implode("\n", ColdFindings::manualPromptLines([])));
    }

    public function testManualPromptNamesNoInstanceSpecificSubAgents(): void
    {
        $prompt = implode("\n", ColdFindings::manualPromptLines(self::findings()));

        foreach (['`critic`', '`verifier`', '`editor`', '`scope-guard`'] as $name) {
            self::assertStringNotContainsString($name, $prompt);
        }
        self::assertStringContainsString('refuted', $prompt);
    }
}
