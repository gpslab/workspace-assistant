<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Known secret values for {@see SecretScanner}, and the moment they change.
 *
 * Three sources, filtered differently:
 *  - the bot's `.env` and the process environment: an include filter by key name
 *    ({@see isSecretKey()}); an exclude list would turn `CLAUDE_EXTRA_ARGS`, `CLAUDE_BIN` or
 *    `GITHUB_REPO` into "secrets" and mask every commit link;
 *  - every file of `SECRET_ENV_FILES`: ALL values, listing a file is the statement that its
 *    values are secret.
 *
 * A value counts only if it is long enough and not one repeated character. The set is built
 * on first use and rebuilt when the modification time or size of a source file changes.
 */
final class SecretSources
{
    public const int MIN_LENGTH = 12;

    private const string KEY_PATTERN = '/TOKEN|SECRET|PASSWORD|PASSWD|CREDENTIAL|_KEY$|_PAT$/';

    /** @var list<array{name: string, value: string}> */
    private array $values = [];

    /** @var array<string, string> */
    private array $signature = [];

    private int $generation = 0;

    private bool $loaded = false;

    /**
     * @param list<string>               $secretFiles `SECRET_ENV_FILES`, `~` is expanded
     * @param array<string, string>|null $environment process environment; null reads the real one
     */
    public function __construct(
        private readonly string $envFile,
        private readonly array $secretFiles = [],
        private readonly ?array $environment = null,
    ) {
    }

    /** Whether the key name of `.env` or of the environment marks its value as a secret. */
    public static function isSecretKey(string $name): bool
    {
        $name = strtoupper($name);

        return $name === 'BOT_TOKEN' || preg_match(self::KEY_PATTERN, $name) === 1;
    }

    /** Whether the value is worth masking: long enough and more than one distinct character. */
    public static function isSecretValue(string $value): bool
    {
        return strlen($value) >= self::MIN_LENGTH && strlen(count_chars($value, 3)) > 1;
    }

    /** Increments every time the set is rebuilt. */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * @return list<array{name: string, value: string}> name is the key of the value in its source
     */
    public function values(): array
    {
        $signature = $this->currentSignature();
        if (!$this->loaded || $signature !== $this->signature) {
            $this->signature = $signature;
            $this->values = $this->build();
            $this->loaded = true;
            ++$this->generation;
        }

        return $this->values;
    }

    /**
     * @return array<string, string> path => "mtime:size", or "missing"
     */
    private function currentSignature(): array
    {
        $signature = [];
        foreach ($this->paths() as $path) {
            clearstatcache(true, $path);
            $mtime = is_file($path) ? @filemtime($path) : false;
            $size = $mtime === false ? false : @filesize($path);
            $signature[$path] = $mtime === false || $size === false ? 'missing' : $mtime.':'.$size;
        }

        return $signature;
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = [$this->envFile];
        foreach ($this->secretFiles as $file) {
            $paths[] = $this->expand($file);
        }

        return $paths;
    }

    private function expand(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = $this->environment['HOME'] ?? getenv('HOME');
            if (is_string($home) && $home !== '') {
                return rtrim($home, '/').substr($path, 1);
            }
        }

        return $path;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function build(): array
    {
        $found = [];
        $add = static function (string $name, string $value) use (&$found): void {
            if (self::isSecretValue($value)) {
                $found[$value] ??= $name;
            }
        };

        $environment = $this->environment ?? getenv();
        foreach ($environment as $name => $value) {
            if (self::isSecretKey((string) $name)) {
                $add((string) $name, $value);
            }
        }

        $envFile = $this->readFile($this->envFile, '.env');
        foreach (self::parseDotenv($envFile) as $name => $value) {
            if (self::isSecretKey($name)) {
                $add($name, $value);
            }
        }

        foreach ($this->secretFiles as $file) {
            $path = $this->expand($file);
            foreach (self::parseDotenv($this->readFile($path, 'SECRET_ENV_FILES')) as $name => $value) {
                $add($name, $value);
            }
        }

        $values = [];
        foreach ($found as $value => $name) {
            $values[] = ['name' => $name, 'value' => (string) $value];
        }

        return $values;
    }

    private function readFile(string $path, string $what): string
    {
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            Log::line("secrets: {$what} file {$path} is not readable, its values are not masked");

            return '';
        }

        return $content;
    }

    /**
     * Dotenv format: `KEY=value`, an optional `export ` prefix, comments, surrounding quotes, a
     * quoted value may span several lines.
     *
     * @return array<string, string>
     */
    public static function parseDotenv(string $content): array
    {
        $result = [];
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        for ($i = 0, $n = count($lines); $i < $n; ++$i) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = (string) preg_replace('/^export\s+/', '', $line);
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = ltrim(substr($line, $eq + 1));
            if ($key === '') {
                continue;
            }

            $quote = $value !== '' ? $value[0] : '';
            if ($quote === '"' || $quote === "'") {
                $body = substr($value, 1);
                while (!str_contains($body, $quote) && $i + 1 < $n) {
                    $body .= "\n".$lines[++$i];
                }
                $end = strpos($body, $quote);
                $value = $end === false ? $body : substr($body, 0, $end);
            } else {
                $value = rtrim($value);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
