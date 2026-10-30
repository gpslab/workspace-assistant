<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Claude Code PreToolUse hook (matcher `Bash`) that gates issue creation in the `pm` and `review`
 * modes: an issue may be created only after the required stages of the PM pipeline
 * ({@see PmRunbook}) ran in the session.
 *
 * It checks the process, not the verdict: a stage counts when the main chain of the transcript
 * holds a synchronous `Agent` call of the stage's agent that completed. What the agent said does
 * not matter. A background call (`async_launched`) and sub-agent entries (`isSidechain`) never count.
 *
 * Window: only stage calls after the last successful issue creation that was followed by a message
 * of the author count. A series of issues created in one batch passes on one set of stages; the next
 * design after the author's reply needs fresh stages.
 *
 * Order: among the required stages the last call of each is not earlier than the last call of the
 * previous one (config order).
 *
 * Skip: where `skip_allowed` of the run kind is true and the model wrote a reason into the file
 * of {@see SKIP_ENV}, the command passes. One reason lifts all missing stages of the window.
 *
 * Detected issue-creating forms, on both forges and regardless of `FORGE`: `gh issue create`, `gh api
 * repos/<o>/<r>/issues` (POST), `glab issue create|new`, `glab api projects/<id>/issues` (POST) and a
 * `createIssue` mutation sent with `gh api graphql` / `glab api graphql` as `-f query=…`. Limitation:
 * Not detected: `-F query=@file` and `--input body.json` (body files are not read), `-F query=@-`
 * (stdin or heredoc), a query held in a shell variable (`-f query="$Q"`), `eval`, `glab -R … mr`, an
 * absolute CLI path and `${VAR:+…}` obfuscation.
 *
 * A command substitution (`$(…)`, backticks; also inside double quotes) stays inside its word, so `projects/$(echo 1)/issues` is
 * recognised as an endpoint; its inner command is still checked as a command of its own. The method is
 * the last `-X`/`--method` outside quoted values ({@see apiIsPost()}), like in gh and glab; a method that
 * is not a literal word counts as POST.
 *
 * The hook is fail-closed (see bin/pm-gate-hook.php): the opposite of the review Stop hook.
 */
final class PmGateHook
{
    public const string SKIP_ENV = 'WORKSPACE_ASSISTANT_PM_SKIP';

    /** What the model sees when the hook itself fails. */
    public const string FAILURE_REASON = 'issue creation blocked: the gate failed, see state/pm-gate.log';

    public const string ALLOW = 'allow';
    public const string DENY = 'deny';

    /** Shell keywords, wrappers and `VAR=value` assignments that may precede the command itself. */
    private const string PREFIX_RE = '/^(?:env(?:\s+(?:-(?:[uCS]|-unset|-chdir|-split-string)\s+\S+|-\S+))*(?=\s)|timeout(?:\s+(?:-[ks]\s+\S+|--?[A-Za-z]\S*))*\s+[\d.]+[smhd]?(?=\s)|(?:(?:if|then|elif|else|do|while|until|time|command|exec|xargs|sudo|nohup)(?:\s+-[^\s=]+)*|[!{])(?=\s)|[A-Za-z_]\w*=(?:"[^"]*"|\'[^\']*\'|\$(?<sub>\((?:[^()]++|(?&sub))*\))|`[^`]*`|\S)*+)\s*/';

    private const string HEREDOC_RE = '/\G<<(?!<)(?<dash>-?)\s*(?:\'(?<word>[^\']+)\'|"(?<dq>[^"]+)"|\\\\?(?<bare>\w+))/';
    private const string GH_ISSUE_CREATE_RE = '/^gh\s+issue\s+create\b/';
    private const string GH_API_RE = '/^gh\s+api\b(?<args>.*)$/s';
    /**
     * A command substitution (`$(…)` with nested parentheses, or backticks): one unit of a path segment, spaces inside
     * allowed. A GitHub `repos/<o>/<r>` may be a single substitution (`repos/$(gh repo view … -q .nameWithOwner)`).
     */
    public const string SUBSTITUTION = '\$\((?:[^()]++|\((?:[^()]++|\([^()]*\))*\))*\)|`[^`]*`';

    private const string ISSUES_ENDPOINT_RE = '~(?<![\w/.-])(?:https?://api\.github\.com)?/?repos/(?:(?:'.self::SUBSTITUTION.'|[^\s/"\'])+/(?:'.self::SUBSTITUTION.'|[^\s/"\'])+|'.self::SUBSTITUTION.')/issues(?=[\s"\'?]|$)~';
    private const string GLAB_ISSUE_CREATE_RE = '/^glab\s+issue\s+(?:create|new)\b/';
    private const string GLAB_API_RE = '/^glab\s+api\b(?<args>.*)$/s';
    /**
     * The `projects/<id>` part of a GitLab API path, shared with {@see ReviewCommitHook}: `<id>` is numeric,
     * a URL-encoded path or `:id`/`:fullpath`; the path may start with `/`, `api/v4/` or `https://<host>/api/v4/`.
     */
    public const string GLAB_PROJECT_PATH = '(?<![\w/.-])(?:https?://[^\s/"\']+/)?/?(?:api/v4/)?projects/(?:'.self::SUBSTITUTION.'|[^\s/"\'?])+';

    private const string GLAB_ISSUES_ENDPOINT_RE = '~'.self::GLAB_PROJECT_PATH.'/issues(?=[\s"\'?]|$)~';
    private const string GRAPHQL_ENDPOINT_RE = '~(?:^|\s)["\']?(?:https?://[^\s"\']+/|/)?(?:api/)?graphql(?=[\s"\'?]|$)~';
    private const string CREATE_ISSUE_MUTATION_RE = '/(?:^|\s)(?:-f|-F|--field|--raw-field)(?:\s+|=)["\']?query=[^@].*\bmutation\b.*\bcreateIssue\b/s';

    /** A field or body flag of `gh api` / `glab api`: without a method flag it makes the request a POST. */
    public const string API_FIELD_FLAG_RE = '/(?:^|\s)(?:-f|-F|--field|--raw-field|--input)(?:[\s=]|$)/';

    /** Whether the command creates an issue (`gh`/`glab issue create`, a POST to the issues endpoint, or a `createIssue` GraphQL mutation). */
    public static function createsIssue(string $command): bool
    {
        foreach (self::commands($command) as $segment) {
            if (preg_match(self::GH_ISSUE_CREATE_RE, $segment) === 1) {
                return true;
            }
            if (preg_match(self::GH_API_RE, $segment, $call) === 1 && (self::apiCreatesIssue($call['args']) || self::graphqlCreatesIssue($call['args']))) {
                return true;
            }
            if (preg_match(self::GLAB_ISSUE_CREATE_RE, $segment) === 1) {
                return true;
            }
            if (preg_match(self::GLAB_API_RE, $segment, $call) === 1 && (self::glabApiCreatesIssue($call['args']) || self::graphqlCreatesIssue($call['args']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple commands of a command line, line continuations joined and wrappers/assignments
     * ({@see PREFIX_RE}) stripped from the front of each: what a PreToolUse hook matches `gh …` against.
     *
     * @return list<string>
     */
    public static function commands(string $command): array
    {
        $commands = [];
        foreach (self::segments(str_replace("\\\n", ' ', $command)) as $segment) {
            while (preg_match(self::PREFIX_RE, $segment, $prefix) === 1) {
                $segment = substr($segment, strlen($prefix[0]));
            }
            $commands[] = $segment;
        }

        return $commands;
    }

    private static function apiCreatesIssue(string $args): bool
    {
        return self::isPost($args, self::ISSUES_ENDPOINT_RE);
    }

    private static function glabApiCreatesIssue(string $args): bool
    {
        return self::isPost($args, self::GLAB_ISSUES_ENDPOINT_RE);
    }

    private static function graphqlCreatesIssue(string $args): bool
    {
        return preg_match(self::GRAPHQL_ENDPOINT_RE, $args) === 1 && preg_match(self::CREATE_ISSUE_MUTATION_RE, $args) === 1;
    }

    private static function isPost(string $args, string $endpointRe): bool
    {
        return preg_match($endpointRe, $args) === 1 && self::apiIsPost($args);
    }

    /**
     * Whether a `gh api` / `glab api` request is a POST: the method is the last `-X`/`--method` outside
     * quoted values (gh and glab keep the last one); a method that is not a literal word (`"$M"`, `$(…)`)
     * is unknown and counts as POST; without a method flag, a field or body flag means POST.
     */
    public static function apiIsPost(string $args): bool
    {
        $words = self::words($args);
        $method = null;
        foreach ($words as $i => $word) {
            if ($word['text'] === '-X' || $word['text'] === '--method') {
                $value = $words[$i + 1] ?? ['text' => '', 'literal' => false];
            } elseif (preg_match('/^(?:-X=?|--method=)(?<v>.*)$/s', $word['text'], $m) === 1) {
                $value = ['text' => $m['v'], 'literal' => $word['literal']];
            } else {
                continue;
            }
            $method = $value['literal'] && preg_match('/^[A-Za-z]+$/', $value['text']) === 1 ? strtoupper($value['text']) : '';
        }
        if ($method !== null) {
            return $method === '' || $method === 'POST';
        }

        return preg_match(self::API_FIELD_FLAG_RE, $args) === 1;
    }

    /**
     * Shell words of a string: quotes removed, a command substitution kept whole inside its word.
     * `literal` is false when the word holds an expansion (`$…`, backticks) outside single quotes.
     *
     * @return list<array{text: string, literal: bool}>
     */
    private static function words(string $s): array
    {
        $words = [];
        $text = '';
        $literal = true;
        $open = false;
        $quote = '';
        $length = strlen($s);
        for ($i = 0; $i < $length; ++$i) {
            $char = $s[$i];
            if ($quote === "'") {
                $quote = $char === "'" ? '' : $quote;
                $text .= $char === "'" ? '' : $char;
            } elseif ($char === '\\' && $i + 1 < $length) {
                $text .= $s[++$i];
                $open = true;
            } elseif ($char === '$' && ($s[$i + 1] ?? '') === '(') {
                $end = self::substitutionEnd($s, $i);
                $text .= substr($s, $i, $end - $i);
                $i = $end - 1;
                $literal = false;
                $open = true;
            } elseif ($char === '`') {
                $end = strpos($s, '`', $i + 1);
                $end = $end === false ? $length : $end + 1;
                $text .= substr($s, $i, $end - $i);
                $i = $end - 1;
                $literal = false;
                $open = true;
            } elseif ($char === '"') {
                $quote = $quote === '"' ? '' : '"';
                $open = true;
            } elseif ($quote === '' && $char === "'") {
                $quote = "'";
                $open = true;
            } elseif ($quote === '' && ctype_space($char)) {
                if ($open) {
                    $words[] = ['text' => $text, 'literal' => $literal];
                }
                $text = '';
                $literal = true;
                $open = false;
            } else {
                $literal = $literal && $char !== '$';
                $text .= $char;
                $open = true;
            }
        }
        if ($open) {
            $words[] = ['text' => $text, 'literal' => $literal];
        }

        return $words;
    }

    /** Offset right after the `)` that closes the `$(` at $at (the end of the string when it is not closed). */
    private static function substitutionEnd(string $s, int $at): int
    {
        $length = strlen($s);
        $depth = 0;
        $quote = '';
        for ($i = $at + 1; $i < $length; ++$i) {
            $char = $s[$i];
            if ($quote === "'") {
                $quote = $char === "'" ? '' : $quote;
            } elseif ($char === '\\') {
                ++$i;
            } elseif ($quote === '"') {
                $quote = $char === '"' ? '' : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return $length;
    }

    /**
     * Simple commands of a command line: split on `; & | ( )` and newlines outside quotes, so a
     * separator inside a quoted argument (`git commit -m "x; gh issue create"`) does not start a command.
     * A command substitution (`$(…)`, backticks; also inside double quotes) stays whole inside its word, and its inner
     * commands follow as segments of their own.
     *
     * @return list<string>
     */
    private static function segments(string $command): array
    {
        $end = 0;

        return self::parse($command, 0, false, $end);
    }

    /**
     * @param bool $nested stop at the `)` that closes a `$(`; $end is the offset of that `)` (or the end of the string)
     *
     * @return list<string>
     */
    private static function parse(string $command, int $start, bool $nested, int &$end): array
    {
        $segments = [];
        /** @var list<string> $inner commands of the substitutions of the current segment */
        $inner = [];
        $current = '';
        $quote = '';
        /** @var list<array{string, bool}> $heredocs delimiter and whether leading tabs are ignored */
        $heredocs = [];
        $arith = 0;
        $depth = 0;
        $length = strlen($command);
        for ($i = $start; $i < $length; ++$i) {
            $char = $command[$i];
            if ($quote === "'") {
                $current .= $char;
                $quote = $char === "'" ? '' : $quote;
            } elseif ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$command[++$i];
            } elseif ($quote === '"' && $char === '$' && substr($command, $i, 2) === '$(' && substr($command, $i, 3) !== '$((') {
                $stop = 0;
                $inner = [...$inner, ...self::parse($command, $i + 2, true, $stop)];
                $current .= substr($command, $i, min($stop + 1, $length) - $i);
                $i = $stop;
            } elseif ($quote === '"' && $char === '`') {
                $close = $i + 1;
                while ($close < $length && $command[$close] !== '`') {
                    $close += $command[$close] === '\\' ? 2 : 1;
                }
                $close = min($close, $length);
                $stop = 0;
                $inner = [...$inner, ...self::parse(substr($command, $i + 1, $close - $i - 1), 0, false, $stop)];
                $current .= substr($command, $i, $close - $i + 1);
                $i = $close;
            } elseif ($quote === '"') {
                $current .= $char;
                $quote = $char === '"' ? '' : $quote;
            } elseif ($char === '"' || $char === "'") {
                $current .= $char;
                $quote = $char;
            } elseif ($arith > 0) {
                // inside $(( )): `<<` is a shift, not a heredoc
                $current .= $char;
                $arith += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            } elseif ($char === '$' && substr($command, $i, 3) === '$((') {
                $current .= '$((';
                $i += 2;
                $arith = 2;
            } elseif ($char === '$' && substr($command, $i, 2) === '$(') {
                $stop = 0;
                $inner = [...$inner, ...self::parse($command, $i + 2, true, $stop)];
                $current .= substr($command, $i, min($stop + 1, $length) - $i);
                $i = $stop;
            } elseif ($char === '`') {
                $close = $i + 1;
                while ($close < $length && $command[$close] !== '`') {
                    $close += $command[$close] === '\\' ? 2 : 1;
                }
                $close = min($close, $length);
                $stop = 0;
                $inner = [...$inner, ...self::parse(substr($command, $i + 1, $close - $i - 1), 0, false, $stop)];
                $current .= substr($command, $i, $close - $i + 1);
                $i = $close;
            } elseif ($char === '<' && substr($command, $i, 3) === '<<<') {
                // here-string: has no body
                $current .= '<<<';
                $i += 2;
            } elseif ($char === '<' && preg_match(self::HEREDOC_RE, $command, $m, 0, $i) === 1) {
                $current .= $m[0];
                $i += strlen($m[0]) - 1;
                $heredocs[] = [($m['word'] ?? '') !== '' ? $m['word'] : (($m['dq'] ?? '') !== '' ? $m['dq'] : ($m['bare'] ?? '')), $m['dash'] !== ''];
            } elseif ($nested && $char === ')' && $depth === 0) {
                $end = $i;
                $segments[] = trim($current);

                return array_values(array_filter([...$segments, ...$inner], static fn (string $s): bool => $s !== ''));
            } elseif (strpbrk($char, ";&|()\n") !== false) {
                $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
                $segments = [...$segments, trim($current), ...$inner];
                $inner = [];
                $current = '';
                if ($char === "\n" && $heredocs !== []) {
                    $i = self::skipHeredocBodies($command, $i + 1, $heredocs) - 1;
                    $heredocs = [];
                }
            } else {
                $current .= $char;
            }
        }
        $end = $length;
        $segments = [...$segments, trim($current), ...$inner];

        return array_values(array_filter($segments, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Offset right after the bodies of the pending heredocs that start at $offset: the text up to the
     * delimiter line is data, not commands, and quotes inside it must not be tracked.
     *
     * @param list<array{string, bool}> $heredocs
     */
    private static function skipHeredocBodies(string $command, int $offset, array $heredocs): int
    {
        $length = strlen($command);
        foreach ($heredocs as [$word, $stripTabs]) {
            while ($offset < $length) {
                $end = strpos($command, "\n", $offset);
                $line = substr($command, $offset, ($end === false ? $length : $end) - $offset);
                $offset = $end === false ? $length : $end + 1;
                if (($stripTabs ? ltrim($line, "\t") : $line) === $word) {
                    break;
                }
            }
        }

        return $offset;
    }

    /**
     * Required stages that are not satisfied: not run (completed) in the window, or run out of order.
     *
     * @param list<string>                                           $transcriptLines
     * @param list<array{id: string, agent: string, required: bool}> $stages
     *
     * @return list<string> human-readable entries
     */
    public static function missingStages(array $transcriptLines, array $stages): array
    {
        $last = self::lastStageCalls($transcriptLines, $stages);
        $missing = [];
        $previous = null;
        $previousId = '';
        foreach ($stages as $stage) {
            if (!$stage['required']) {
                continue;
            }
            $at = $last[$stage['id']] ?? null;
            if ($at === null) {
                $missing[] = "{$stage['id']} (agent {$stage['agent']})";
                continue;
            }
            if ($previous !== null && $at < $previous) {
                $missing[] = "{$stage['id']} (agent {$stage['agent']}) — ran before {$previousId}, run it again after it";
                continue;
            }
            $previous = $at;
            $previousId = $stage['id'];
        }

        return $missing;
    }

    /**
     * Line number of the last completed main-chain call of each stage agent inside the window.
     *
     * @param list<string>                                           $lines
     * @param list<array{id: string, agent: string, required: bool}> $stages
     *
     * @return array<string, int> stage id => line index
     */
    private static function lastStageCalls(array $lines, array $stages): array
    {
        /** @var array<string, array{at: int, agent: string}> $agentCalls tool_use id => call */
        $agentCalls = [];
        /** @var array<string, bool> $issueCalls tool_use id => true */
        $issueCalls = [];
        /** @var array<string, string> $statuses tool_use id => status */
        $statuses = [];
        $windowStart = -1;
        $lastCreate = null;

        foreach ($lines as $at => $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry) || ($entry['isSidechain'] ?? false) === true) {
                continue;
            }
            if (ReviewStatusHook::isRealUserMessage($entry)) {
                if ($lastCreate !== null) {
                    $windowStart = $lastCreate;
                    $lastCreate = null;
                }
                continue;
            }
            foreach (self::blocks($entry) as $block) {
                $type = $block['type'] ?? null;
                $id = (string) ($block['id'] ?? $block['tool_use_id'] ?? '');
                if ($type === 'tool_use' && ($entry['type'] ?? null) === 'assistant') {
                    if (($block['name'] ?? null) === 'Agent' && is_string($block['input']['subagent_type'] ?? null)) {
                        $agentCalls[$id] = ['at' => $at, 'agent' => $block['input']['subagent_type']];
                    } elseif (($block['name'] ?? null) === 'Bash' && is_string($block['input']['command'] ?? null) && self::createsIssue($block['input']['command'])) {
                        $issueCalls[$id] = true;
                    }
                } elseif ($type === 'tool_result' && ($entry['type'] ?? null) === 'user') {
                    $result = $entry['toolUseResult'] ?? null;
                    if (is_array($result) && is_string($result['status'] ?? null)) {
                        $statuses[$id] = $result['status'];
                    }
                    if (isset($issueCalls[$id]) && ($block['is_error'] ?? false) !== true) {
                        $lastCreate = $at;
                    }
                }
            }
        }

        $byAgent = [];
        foreach ($agentCalls as $id => $call) {
            if ($call['at'] > $windowStart && ($statuses[$id] ?? null) === 'completed') {
                $byAgent[$call['agent']] = max($byAgent[$call['agent']] ?? -1, $call['at']);
            }
        }
        $last = [];
        foreach ($stages as $stage) {
            if (isset($byAgent[$stage['agent']])) {
                $last[$stage['id']] = $byAgent[$stage['agent']];
            }
        }

        return $last;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $entry): array
    {
        $content = $entry['message']['content'] ?? null;

        return is_array($content) ? array_values(array_filter($content, 'is_array')) : [];
    }

    /**
     * The decision for one issue-creating command.
     *
     * @param list<string>                                                                                             $transcriptLines
     * @param array{stages: list<array{id: string, agent: string, required: bool}>, skip_allowed: array<string, bool>} $config          snapshot
     *
     * @return array{decision: self::ALLOW|self::DENY, reason: string, skipped: bool}
     */
    public static function decide(array $transcriptLines, array $config, string $kind, string $skipReason): array
    {
        $missing = self::missingStages($transcriptLines, $config['stages']);
        if ($missing === []) {
            return ['decision' => self::ALLOW, 'reason' => '', 'skipped' => false];
        }
        $skipAllowed = ($config['skip_allowed'][$kind] ?? false) === true;
        if ($skipAllowed && trim($skipReason) !== '') {
            return ['decision' => self::ALLOW, 'reason' => '', 'skipped' => true];
        }

        $reason = 'Issue creation blocked: these required stages of the PM pipeline have not completed since the last author message: '
            .implode('; ', $missing).'. Run each missing stage as a synchronous Agent call (run_in_background: false) in the order of the runbook, then create the issue.';
        if ($skipAllowed) {
            $reason .= ' This kind of run allows skipping: write the reason for skipping into the file named by the environment variable '
                .self::SKIP_ENV.' and repeat the command.';
        }

        return ['decision' => self::DENY, 'reason' => $reason, 'skipped' => false];
    }

    /** JSON the hook prints to deny the command. */
    public static function denyJson(string $reason): string
    {
        return (string) json_encode(
            ['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'permissionDecision' => 'deny', 'permissionDecisionReason' => $reason]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function snapshotPath(string $stateDir, string $runId): string
    {
        return rtrim($stateDir, '/').'/pm-gate/'.$runId.'.json';
    }

    public static function skipPath(string $stateDir, string $runId): string
    {
        return rtrim($stateDir, '/').'/pm-gate/'.$runId.'.skip';
    }

    /** Marker written by the hook when a skip reason actually let an issue through. */
    public static function appliedPath(string $stateDir, string $runId): string
    {
        return rtrim($stateDir, '/').'/pm-gate/'.$runId.'.applied';
    }

    public static function logPath(string $stateDir): string
    {
        return rtrim($stateDir, '/').'/pm-gate.log';
    }

    /**
     * Write the config snapshot of a run.
     *
     * @param array{stages: list<array{id: string, agent: string, required: bool}>, skip_allowed: array<string, bool>, warnings?: list<string>} $config
     *
     * @throws \RuntimeException
     */
    public static function writeSnapshot(string $stateDir, string $runId, array $config): void
    {
        $path = self::snapshotPath($stateDir, $runId);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('cannot create '.dirname($path));
        }
        $json = json_encode(['stages' => $config['stages'], 'skip_allowed' => $config['skip_allowed']], JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('cannot write '.$path);
        }
        @unlink(self::skipPath($stateDir, $runId));
    }

    /**
     * @return array{stages: list<array{id: string, agent: string, required: bool}>, skip_allowed: array<string, bool>}
     *
     * @throws \RuntimeException
     */
    public static function readSnapshot(string $stateDir, string $runId): array
    {
        $data = json_decode((string) @file_get_contents(self::snapshotPath($stateDir, $runId)), true);
        if (!is_array($data) || !is_array($data['stages'] ?? null) || !is_array($data['skip_allowed'] ?? null)) {
            throw new \RuntimeException('config snapshot of run '.$runId.' is missing or invalid');
        }
        $stages = [];
        foreach ($data['stages'] as $stage) {
            if (!is_array($stage) || !is_string($stage['id'] ?? null) || !is_string($stage['agent'] ?? null) || !is_bool($stage['required'] ?? null)) {
                throw new \RuntimeException('config snapshot of run '.$runId.' has an invalid stage');
            }
            $stages[] = ['id' => $stage['id'], 'agent' => $stage['agent'], 'required' => $stage['required']];
        }
        $skip = [];
        foreach ($data['skip_allowed'] as $key => $value) {
            $skip[(string) $key] = $value === true;
        }

        return ['stages' => $stages, 'skip_allowed' => $skip];
    }

    public static function cleanup(string $stateDir, string $runId): void
    {
        @unlink(self::snapshotPath($stateDir, $runId));
        @unlink(self::skipPath($stateDir, $runId));
        @unlink(self::appliedPath($stateDir, $runId));
    }

    /** The skip reason that was actually applied by the gate in a run; '' when none. */
    public static function skipReason(string $stateDir, string $runId): string
    {
        $path = self::appliedPath($stateDir, $runId);

        return is_file($path) ? trim((string) @file_get_contents($path)) : '';
    }

    /**
     * JSON for `claude --settings`: the hooks of a mode. The PreToolUse gate command is wrapped:
     * a hook that dies (PHP fatal, exit 255) would not block the command in Claude Code, so any
     * non-zero exit becomes exit 2 with a fixed reason on stderr.
     *
     * @param bool $reviewStop also attach the review Stop hook (review mode)
     */
    public static function settingsJson(string $stateDir, string $kind, string $runId, bool $reviewStop, string $forge): string
    {
        $inner = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg(dirname(__DIR__).'/bin/pm-gate-hook.php').' '
            .escapeshellarg($stateDir).' '
            .escapeshellarg($kind).' '
            .escapeshellarg($runId);
        $command = '{ '.$inner.' || { echo '.escapeshellarg(self::FAILURE_REASON).' >&2; false; }; } || exit 2';

        $hooks = ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => $command]]]]];
        if ($reviewStop) {
            $stop = json_decode(ReviewStatusHook::settingsJson($stateDir, $forge), true, 512, JSON_THROW_ON_ERROR);
            $hooks = array_merge($stop['hooks'], $hooks);
        }

        return (string) json_encode(['hooks' => $hooks], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
