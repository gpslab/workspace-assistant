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
}
