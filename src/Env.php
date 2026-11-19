<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Minimal .env loader (without Composer / vlucas/phpdotenv).
 *
 * Supports: KEY=VALUE, blank lines, comments (#), surrounding quotes.
 * Real environment variables (getenv) take precedence over the file — handy
 * for overriding values at deploy time without touching .env. The file-only mode
 * (see load()) ignores the real environment and reads the file alone.
 */
final class Env
{
    /** @var array<string, string> */
    private array $values = [];

    private bool $fileOnly = false;

    public static function load(string $path, bool $fileOnly = false): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("The .env file was not found at {$path}. Copy .env.dist to .env.");
        }

        $env = new self();
        $env->fileOnly = $fileOnly;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            // Strip surrounding quotes (single or double).
            if (strlen($value) >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }

            $env->values[$key] = $value;
        }

        return $env;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (!$this->fileOnly) {
            $real = getenv($key);
            if ($real !== false) {
                return $real;
            }
        }

        return $this->values[$key] ?? $default;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("{$key} is not set in .env.");
        }

        return $value;
    }

    /**
     * Comma-separated list value. Empty items are dropped.
     *
     * @return string[]
     */
    public function list(string $key, string $default = ''): array
    {
        $raw = (string) $this->get($key, $default);
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * The `.env` keys Claude Code reads for an Anthropic-compatible provider: every key whose
     * name starts with `ANTHROPIC_` or `CLAUDE_CODE_`. Both are Claude Code's own namespaces,
     * so a fixed list would go stale with the next Claude Code release that adds a variable.
     *
     * File values only, regardless of {@see self::$fileOnly}: the real environment reaches the
     * process on its own, so the caller must merge these BELOW `getenv()` to keep the existing
     * precedence (the real environment wins over the file, as in {@see self::get()}).
     *
     * @return array<string, string>
     */
    public function providerEnv(): array
    {
        $result = [];
        foreach ($this->values as $key => $value) {
            if (str_starts_with($key, 'ANTHROPIC_') || str_starts_with($key, 'CLAUDE_CODE_')) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
