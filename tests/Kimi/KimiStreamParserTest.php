<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Kimi;

use GpsLab\WorkspaceAssistant\Kimi\KimiStream;
use GpsLab\WorkspaceAssistant\Kimi\KimiStreamParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KimiStreamParser::class)]
#[CoversClass(KimiStream::class)]
final class KimiStreamParserTest extends TestCase
{
    public function testToolRunYieldsTheVersionTheProgressTheAnswerAndTheSession(): void
    {
        $stream = $this->parseRun('02-tool');

        self::assertSame('2.7.2', $stream->version);
        self::assertSame(['Read a.txt'], $stream->progressLines);
        self::assertSame('hello', $stream->finalText);
        self::assertSame('session_d83400d1-2a14-469a-af85-a7b2cc4e8d0b', $stream->sessionId);
        self::assertNull($stream->error);
        self::assertSame(0, $stream->retrying);
    }

    public function testRunWithoutToolsYieldsTheAnswerAndNoProgress(): void
    {
        $stream = $this->parseRun('01-plain');

        self::assertSame('2.7.2', $stream->version);
        self::assertSame([], $stream->progressLines);
        self::assertSame('OK', $stream->finalText);
        self::assertSame('session_69baaee8-5306-4648-b06b-465a8bb88e1f', $stream->sessionId);
    }

    public function testResumedRunYieldsTheAnswerAndTheSessionIdOfTheSession(): void
    {
        $first = $this->parseRun('03-resume-1');
        $second = $this->parseRun('03-resume-2');

        self::assertSame('OK — remembered: 4711.', $first->finalText);
        // The second run answers 4711: it is the same session, so its id is the one to resume.
        self::assertSame('4711', $second->finalText);
        self::assertSame('session_6ce594e9-d8a1-4081-8eb7-82dd68584f71', $second->sessionId);
        self::assertSame($first->sessionId, $second->sessionId);
    }

    /**
     * A run that fails before the session starts prints only the version: no `resume_hint`, no
     * answer. Both the bad model alias (exit 1) and the unknown session (exit 1) look like this.
     */
    public function testConfigFailureYieldsNoSessionIdAndNoAnswer(): void
    {
        foreach (['04-bad-model', '05-bad-session'] as $scenario) {
            $stream = $this->parseRun($scenario);

            self::assertSame('2.7.2', $stream->version, $scenario);
            self::assertNull($stream->sessionId, $scenario);
            self::assertSame('', $stream->finalText, $scenario);
            self::assertNull($stream->error, $scenario);
        }
    }

    public function testOccupiedSessionYieldsTheErrorEvent(): void
    {
        $stream = $this->parseRun('06-occupied-second');

        self::assertSame('2.7.2', $stream->version);
        self::assertNull($stream->sessionId);
        self::assertSame('', $stream->finalText);
        self::assertSame(
            [
                'code' => 'session.occupied',
                'message' => 'This session is in use by another Kimi Code CLI instance. Stop the session in that instance or exit it, then try again.',
            ],
            $stream->error,
        );
    }

    /**
     * The hook failure notice ("PreToolUse hook … failed to execute …") is an `assistant` message
     * and the failed call is repeated after it: the answer is the last text of the run, not the notice.
     */
    public function testHookFailureNoticeIsNotTheAnswer(): void
    {
        $stream = $this->parseRun('08-hook-exit1');

        self::assertSame('MARK-hook-exit1', $stream->finalText);
        self::assertStringNotContainsString('failed to execute', $stream->finalText);
        // The call is printed twice (before and after the notice), the notice itself is no progress.
        self::assertSame(['Bash echo MARK-hook-exit1', 'Bash echo MARK-hook-exit1'], $stream->progressLines);
    }

    public function testDeniedCallYieldsTheAnswerAfterTheDeniedCall(): void
    {
        $stream = $this->parseRun('07-hook-deny');

        // No notice here (the hook exited 2 with a reason): the only text without a call is the answer.
        self::assertSame('denied by the probe', $stream->finalText);
        self::assertSame(['Bash echo MARK-hook-deny'], $stream->progressLines);
        self::assertSame('session_325bd6a3-66e9-494b-8a01-7a954130d161', $stream->sessionId);
    }

    /**
     * stdout is not the history of the run: the `tool` message of a background sub-agent says
     * `status: completed` while the agent still runs, and it is printed after the answer.
     */
    public function testBackgroundSubagentStatusIsNotTheAnswer(): void
    {
        $stream = $this->parseRun('14-subagent-background');

        self::assertSame(
            "The critic subagent finished. Its verdict on \"grass is blue\":\n\n"
            .'**False** — grass is typically green, though certain species or lighting can give a blue-green tint.',
            $stream->finalText,
        );
        self::assertStringNotContainsString('status: completed', $stream->finalText);
        self::assertCount(2, $stream->progressLines);
        self::assertSame('Agent Critique grass color claim', $stream->progressLines[0]);
        self::assertSame('session_3129066e-f426-4ed3-a232-8fbdecc195eb', $stream->sessionId);
    }

    /**
     * A `tool` message is the result of a call, never the answer: the notification of a background
     * sub-agent is printed after the answer and says `status: completed`.
     */
    public function testToolMessageIsNotTheAnswer(): void
    {
        $stream = KimiStreamParser::parse([
            '{"role":"assistant","content":"the answer"}',
            '{"role":"tool","tool_call_id":"tool_1","content":"agent_id: agent-0\nstatus: completed\n\n[summary]\na summary"}',
        ]);

        self::assertSame('the answer', $stream->finalText);
    }

    public function testRetriedProviderErrorIsCounted(): void
    {
        $stream = $this->parseRun('30-mock-rate');

        self::assertSame(1, $stream->retrying);
        // The rate limit is a `wire.jsonl` error: the stream carries no `error` event for it.
        self::assertNull($stream->error);
        self::assertSame('', $stream->finalText);
        self::assertNull($stream->sessionId);
    }

    public function testInvalidLinesAreSkipped(): void
    {
        $stream = KimiStreamParser::parse([
            'not json',
            '42',
            '"a scalar"',
            '{"role":"assistant"}',
            '{"role":"assistant","tool_calls":"nonsense"}',
            '{"role":"tool","tool_call_id":"tool_1","content":"status: completed"}',
            '{"type":"system.version"}',
            '{"type":"session.resume_hint","session_id":123}',
            // Only the resume hint names the session: another shape with a session_id is not one.
            '{"type":"session.info","session_id":"session_other"}',
            '{"type":"error"}',
            '{"role":"meta","type":"system.version","version":"2.7.2"}',
            '{"role":"assistant","content":"the answer"}',
        ]);

        self::assertSame('2.7.2', $stream->version);
        self::assertSame([], $stream->progressLines);
        self::assertSame('the answer', $stream->finalText);
        self::assertNull($stream->sessionId);
        self::assertNull($stream->error);
    }

    /**
     * A text that a tool call follows is a status line, not the answer: a run that ends on the
     * repeated call (killed mid-tool) has no answer at all.
     */
    public function testTextFollowedByAToolCallIsNotTheAnswer(): void
    {
        $stream = KimiStreamParser::parse([
            '{"role":"assistant","tool_calls":[{"type":"function","id":"tool_1","function":{"name":"Bash","arguments":"{\"command\":\"sleep 60\"}"}}]}',
            '{"role":"assistant","content":"PreToolUse hook\n\nA PreToolUse hook failed to execute: exited with exit code 1."}',
            '{"role":"assistant","tool_calls":[{"type":"function","id":"tool_1","function":{"name":"Bash","arguments":"{\"command\":\"sleep 60\"}"}}]}',
        ]);

        self::assertSame('', $stream->finalText);
        self::assertSame('', $this->parseRun('20-killed-mid-tool')->finalText);
    }

    public function testOneProgressLinePerToolCallOfTheMessage(): void
    {
        $stream = KimiStreamParser::parse([
            '{"role":"assistant","tool_calls":['
            .'{"type":"function","id":"tool_1","function":{"name":"Glob","arguments":"{\"pattern\":\".agents/**/*\",\"path\":\"/work/proj\"}"}},'
            .'{"type":"function","id":"tool_2","function":{"name":"Grep","arguments":"{\"pattern\":\"runbook\",\"path\":\"/work/proj\"}"}},'
            .'{"type":"function","id":"tool_3","function":{"name":"CronCreate","arguments":"{\"cron\":\"0 0 1 1 *\",\"prompt\":\"noop\"}"}}'
            .']}',
        ]);

        self::assertSame(['Glob .agents/**/*', 'Grep runbook', 'CronCreate noop'], $stream->progressLines);
    }

    /**
     * A call whose arguments the summary does not know is printed by its name alone: the line never
     * becomes a dump of the raw arguments, and a long argument stays one short line.
     */
    public function testProgressLineFallsBackToTheToolNameAndClipsALongArgument(): void
    {
        $long = str_repeat('a', 200);
        $stream = KimiStreamParser::parse([
            '{"role":"assistant","tool_calls":[{"type":"function","id":"tool_1","function":{"name":"TaskList","arguments":"{\"limit\":5}"}}]}',
            '{"role":"assistant","tool_calls":[{"type":"function","id":"tool_2","function":{"name":"Bash","arguments":"not json"}}]}',
            '{"role":"assistant","tool_calls":[{"type":"function","id":"tool_3","function":{"name":"Read","arguments":"{\"path\":\"'.$long.'\"}"}}]}',
            '{"role":"assistant","tool_calls":[{"type":"function","id":"tool_4"}]}',
        ]);

        self::assertSame('TaskList', $stream->progressLines[0]);
        self::assertSame('Bash', $stream->progressLines[1]);
        // 80 characters of the argument, the ellipsis and "Read " before them.
        self::assertSame('Read '.str_repeat('a', 80).'…', $stream->progressLines[2]);
        self::assertSame('Tool', $stream->progressLines[3]);
    }

    /**
     * A multi-line or double-spaced argument is collapsed to one line of single spaces: a raw line
     * break (a heredoc, or `cd x\nmake`) must not break the progress line.
     */
    public function testProgressLineCollapsesWhitespaceToOneLine(): void
    {
        $stream = KimiStreamParser::parse([
            '{"role":"assistant","tool_calls":[{"type":"function","id":"tool_1","function":{"name":"Bash","arguments":"{\"command\":\"cd /work\\\\n\\\\n  make   test\"}"}}]}',
        ]);

        self::assertSame('Bash cd /work make test', $stream->progressLines[0]);
    }

    public function testOnlyTheFirstVersionAndTheFirstSessionIdAreKept(): void
    {
        $stream = KimiStreamParser::parse([
            '{"role":"meta","type":"system.version","version":"2.7.2"}',
            '{"role":"meta","type":"session.resume_hint","session_id":"session_first"}',
            '{"role":"meta","type":"system.version","version":"2.7.3"}',
            '{"role":"meta","type":"session.resume_hint","session_id":"session_second"}',
            '{"type":"error","code":"provider.retry","message":"first"}',
            '{"type":"error","code":"provider.give_up","message":"second"}',
        ]);

        self::assertSame('2.7.2', $stream->version);
        self::assertSame('session_first', $stream->sessionId);
        self::assertSame(['code' => 'provider.retry', 'message' => 'first'], $stream->error);
    }

    public function testTheAnswerIsTheLastTextWithoutAToolCallAndKeepsItsLines(): void
    {
        $stream = KimiStreamParser::parse([
            '{"role":"assistant","content":"first"}',
            '{"role":"assistant","content":"the last one\n\non two paragraphs"}',
        ]);

        self::assertSame("the last one\n\non two paragraphs", $stream->finalText);
    }

    private function parseRun(string $scenario): KimiStream
    {
        $file = __DIR__.'/../fixtures/kimi/run/'.$scenario.'.stdout.jsonl';
        $content = (string) file_get_contents($file);

        return KimiStreamParser::parse(array_values(array_filter(
            explode("\n", $content),
            static fn (string $line): bool => trim($line) !== '',
        )));
    }
}
