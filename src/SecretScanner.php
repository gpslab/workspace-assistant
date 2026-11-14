<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Finds secrets in outgoing text and replaces them with {@see self::PLACEHOLDER}.
 *
 * Known values ({@see SecretSources}) run first, then the shape patterns: a pattern can cut
 * off the prefix of a key and leave its tail in the text. Every known value is matched in all
 * its forms (raw, per line, urlencode with hex in any case, JSON-escaped, base64, base64url,
 * HTML-escaped) as one alternation, longest first. All matches are found in the original text
 * and overlapping ones are merged, so no partial tail of a value stays behind.
 *
 * Policy: mask and send, never block. A finding and the log carry only the type and the
 * location, never any part of the value. Plain PHP, no dependencies.
 */
final class SecretScanner
{
    public const string PLACEHOLDER = '[REDACTED]';

    /** Upper bound of one compiled alternation, in characters: PCRE refuses patterns that are too big. */
    private const int CHUNK_LENGTH = 20000;

    /** Shape patterns: name => regex. */
    private const array PATTERNS = [
        'github_token' => '~gh[pousr]_[A-Za-z0-9]{36,}~',
        'github_pat' => '~github_pat_[A-Za-z0-9_]{22,}~',
        'gitlab_token' => '~glpat-[A-Za-z0-9_-]{20,}~',
        'anthropic_key' => '~sk-ant-[A-Za-z0-9_-]{20,}~',
        'openai_key' => '~(?<![A-Za-z0-9_-])sk-[A-Za-z0-9_-]{20,}~',
        'slack_token' => '~xox[abpr]-[A-Za-z0-9-]{10,}~',
        'aws_access_key' => '~AKIA[A-Z0-9]{16}~',
        'google_api_key' => '~AIza[A-Za-z0-9_-]{35}~',
        'telegram_bot_token' => '~(?<!\d)\d{8,10}:[A-Za-z0-9_-]{35}~',
        'jwt' => '~eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]*~',
        // An unterminated block (a clipped text) is masked to the end.
        'private_key' => '~-----BEGIN [A-Z ]*PRIVATE KEY-----(?:[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----|[\s\S]*)~',
        'url_password' => '~[a-zA-Z][a-zA-Z0-9+.-]*://[^\s:/@]+:\K[^\s/]+(?=@)~',
    ];

    private static ?self $shared = null;

    /** @var list<string> */
    private array $knownChunks = [];

    /** @var array<string, string> normalized form => key name */
    private array $knownTypes = [];

    private int $knownGeneration = -1;

    public function __construct(private readonly ?SecretSources $sources = null)
    {
    }

    /** The scanner of the process: set by `bin/bot.php`, shape patterns only until then. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public static function setShared(?self $scanner): void
    {
        self::$shared = $scanner;
    }

    /**
     * Masks the text and logs the fact of a finding and its types (never a value).
     */
    public function mask(string $text): string
    {
        ['text' => $masked, 'findings' => $findings] = $this->scan($text);
        if ($findings !== []) {
            Log::line('secrets: masked '.count($findings).' in outgoing text: '.implode(', ', array_unique(array_map(static fn (SecretFinding $f): string => $f->type, $findings))));
        }

        return $masked;
    }

    /**
     * @param bool $shapes false looks for known values only (paths of `SECRET_SCAN_EXCLUDE`)
     *
     * @return array{text: string, findings: list<SecretFinding>} locations are in the ORIGINAL text
     */
    public function scan(string $text, bool $shapes = true): array
    {
        if ($text === '') {
            return ['text' => $text, 'findings' => []];
        }

        /** @var list<array{int, int, string}> $ranges start, end, type */
        $ranges = [];
        $this->refreshKnown();
        foreach ($this->knownChunks as $regex) {
            foreach ($this->matches($regex, $text) as [$match, $offset]) {
                $ranges[] = [$offset, $offset + strlen($match), 'known:'.($this->knownTypes[self::normalize($match)] ?? 'value')];
            }
        }
        foreach ($shapes ? self::PATTERNS : [] as $type => $regex) {
            foreach ($this->matches($regex, $text) as [$match, $offset]) {
                if ($type === 'url_password' && self::isPlaceholderPassword($match)) {
                    continue;
                }
                $ranges[] = [$offset, $offset + strlen($match), $type];
            }
        }
        if ($ranges === []) {
            return ['text' => $text, 'findings' => []];
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end, $type]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start < $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end, $type];
            }
        }

        $out = '';
        $pos = 0;
        $findings = [];
        foreach ($merged as [$start, $end, $type]) {
            $out .= substr($text, $pos, $start - $pos).self::PLACEHOLDER;
            $pos = $end;
            $findings[] = new SecretFinding($type, $start, substr_count($text, "\n", 0, $start) + 1);
        }

        return ['text' => $out.substr($text, $pos), 'findings' => $findings];
    }

    /**
     * @return list<array{string, int}> match, byte offset
     */
    private function matches(string $regex, string $text): array
    {
        if (@preg_match_all($regex, $text, $m, PREG_OFFSET_CAPTURE) === false) {
            Log::line('secrets: a pattern failed to run ('.preg_last_error_msg().')');

            return [];
        }

        /** @var list<array{string, int}> $matches */
        $matches = $m[0];

        return array_values(array_filter($matches, static fn (array $x): bool => $x[0] !== ''));
    }

    /** The whole password part of a URL is an obvious documentation placeholder, not a secret. */
    private static function isPlaceholderPassword(string $password): bool
    {
        return preg_match('~^(?:<[^<>{}$]+>|\$?\{[^<>{}$]+\}|\$[A-Za-z_][A-Za-z0-9_]*|%[A-Za-z_][A-Za-z0-9_]*%|\*+|(?i:password|pass|passwd|pwd|secret|token))$~D', $password) === 1;
    }

    private function refreshKnown(): void
    {
        if ($this->sources === null) {
            return;
        }
        $values = $this->sources->values();
        if ($this->sources->generation() === $this->knownGeneration) {
            return;
        }
        $this->knownGeneration = $this->sources->generation();

        /** @var array<string, array{int, string}> $forms regex => length of the literal, for the sort */
        $forms = [];
        $types = [];
        foreach ($values as ['name' => $name, 'value' => $value]) {
            $parts = [$value];
            if (str_contains($value, "\n")) {
                foreach (preg_split('/\r\n|\n|\r/', $value) ?: [] as $line) {
                    if (SecretSources::isSecretValue($line)) {
                        $parts[] = $line;
                    }
                }
            }
            foreach ($parts as $part) {
                foreach (self::formsOf($part) as [$literal, $regex]) {
                    $forms[$regex] ??= [strlen($literal), $regex];
                    $types[self::normalize($literal)] ??= $name;
                }
            }
        }

        uasort($forms, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $this->knownTypes = $types;
        $this->knownChunks = [];
        $current = [];
        $length = 0;
        foreach ($forms as [, $regex]) {
            if ($length + strlen($regex) > self::CHUNK_LENGTH && $current !== []) {
                $this->knownChunks[] = '~(?:'.implode('|', $current).')~';
                $current = [];
                $length = 0;
            }
            $current[] = $regex;
            $length += strlen($regex) + 1;
        }
        if ($current !== []) {
            $this->knownChunks[] = '~(?:'.implode('|', $current).')~';
        }
    }

    /**
     * Forms of one value as they can appear in a message.
     *
     * @return list<array{string, string}> literal (for the sort and the type lookup), regex source
     */
    private static function formsOf(string $value): array
    {
        $b64 = base64_encode($value);
        $b64url = strtr($b64, '+/', '-_');
        $literals = [
            $value,
            substr((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1),
            substr((string) json_encode($value), 1, -1),
            $b64,
            rtrim($b64, '='),
            $b64url,
            rtrim($b64url, '='),
            htmlspecialchars($value, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($value, ENT_NOQUOTES),
        ];

        $forms = [];
        foreach (array_unique($literals) as $literal) {
            if ($literal !== '') {
                $forms[] = [$literal, preg_quote($literal, '~')];
            }
        }

        // urlencode: hex digits may be in either case, so they are matched as classes.
        foreach (array_unique([rawurlencode($value), urlencode($value)]) as $encoded) {
            if ($encoded === $value) {
                continue;
            }
            $regex = '';
            foreach (preg_split('/(%[0-9A-Fa-f]{2})/', $encoded, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $piece) {
                $regex .= $i % 2 === 1
                    ? '%'.implode('', array_map(static fn (string $c): string => ctype_alpha($c) ? '['.strtolower($c).strtoupper($c).']' : $c, str_split(substr($piece, 1))))
                    : preg_quote($piece, '~');
            }
            $forms[] = [$encoded, $regex];
        }

        return $forms;
    }

    /** Key of the type lookup: the hex digits of a percent-encoded form are case-insensitive. */
    private static function normalize(string $form): string
    {
        return (string) preg_replace_callback('/%[0-9A-Fa-f]{2}/', static fn (array $m): string => strtoupper($m[0]), $form);
    }
}
