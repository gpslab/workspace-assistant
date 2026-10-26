<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

use GpsLab\WorkspaceAssistant\Epic\Run;

/**
 * The PM runbook: the stages of working out a task, and the pipeline config in its first
 * fenced ```json block (the same convention as the epic plan, see {@see Epic\Plan}).
 *
 * The bot does not run the stages: Claude does, following the runbook. The code only defines,
 * parses, validates and hands out the config.
 *
 * Block format:
 *
 *     {
 *       "stages": [{"id": "critique", "agent": "critic", "required": true}],
 *       "skip_allowed": {"pm": false, "review": true, "tick": false, "final": false, "epic_review": true}
 *     }
 *
 * Layers: the shipped `prompts/pm_runbook.md.dist`, replaced as a whole by a local
 * `prompts/pm_runbook.md` ({@see PromptFiles}), then the optional instance addition
 * `<PROMPTS_DIR>/pm_runbook.md`: its text follows the runbook, its json block replaces `stages` and
 * overrides `skip_allowed` by key.
 */
final class PmRunbook
{
    /** Name of the runbook text in the bot's `prompts/` directory. */
    public const string FILE = 'pm_runbook';

    /** Instance addition to the runbook inside `PROMPTS_DIR`; optional. */
    public const string ADDITION_FILE = 'pm_runbook.md';

    public const string SKIP_PM = 'pm';
    public const string SKIP_REVIEW = 'review';
    public const string SKIP_TICK = 'tick';
    public const string SKIP_FINAL = 'final';
    public const string SKIP_EPIC_REVIEW = 'epic_review';

    /** @var list<string> */
    public const array SKIP_KEYS = [self::SKIP_PM, self::SKIP_REVIEW, self::SKIP_TICK, self::SKIP_FINAL, self::SKIP_EPIC_REVIEW];

    private const string BLOCK_RE = '/^```json[ \t]*\R(.*?)\R```[ \t]*$/ms';

    private const string ID_RE = '/^[a-z][a-z0-9_-]*$/';

    /** Absolute path to the runbook: the local `prompts/pm_runbook.md` if present, else the shipped `.dist`. */
    public static function runbookPath(): string
    {
        return PromptFiles::resolvedPath(self::FILE);
    }

    /** Absolute path to the instance addition, or null when it is not set or does not exist. */
    public static function additionPath(?string $promptsDir): ?string
    {
        if ($promptsDir === null || $promptsDir === '') {
            return null;
        }
        $path = rtrim($promptsDir, '/').'/'.self::ADDITION_FILE;

        return is_file($path) ? $path : null;
    }

    /** The conditional runbook line placed at the start of a pm message and of an epic run prompt. */
    public static function line(?string $promptsDir): string
    {
        $line = 'If this message starts or continues working out a task, follow the PM runbook: read '.self::runbookPath();
        $addition = self::additionPath($promptsDir);
        if ($addition !== null) {
            $line .= ' (and the instance addition '.$addition.')';
        }

        return $line;
    }

    /** The key of `skip_allowed` for a chat mode; null for a mode without a pipeline (`arch`). */
    public static function skipKeyForMode(string $mode): ?string
    {
        return match ($mode) {
            SystemPrompt::MODE_PM => self::SKIP_PM,
            SystemPrompt::MODE_REVIEW => self::SKIP_REVIEW,
            default => null,
        };
    }

    /** The key of `skip_allowed` for an epic run kind; the run name `review` is not the `review` chat. */
    public static function skipKeyForEpicRun(string $run): ?string
    {
        return match ($run) {
            Run::TICK => self::SKIP_TICK,
            Run::FINAL => self::SKIP_FINAL,
            Run::REVIEW => self::SKIP_EPIC_REVIEW,
            default => null,
        };
    }

    /**
     * The runbook text followed by the instance addition text, if any.
     *
     * @param list<string> $warnings
     */
    public static function text(?string $promptsDir, array &$warnings = []): string
    {
        $text = PromptFiles::load(self::FILE, $warnings);
        $addition = self::additionPath($promptsDir);
        $extra = $addition !== null ? trim((string) @file_get_contents($addition)) : '';

        return $extra === '' ? $text : $text."\n\n".$extra;
    }

    /**
     * The effective config. An invalid config of the local runbook or of the addition gives way to
     * the `.dist` one with a warning.
     *
     * @return array{stages: list<array{id: string, agent: string, required: bool}>, skip_allowed: array<string, bool>, warnings: list<string>}
     *
     * @throws \RuntimeException when the shipped `.dist` is missing or its config is invalid
     */
    public static function config(?string $promptsDir): array
    {
        $warnings = [];
        $dist = self::parseBase(PromptFiles::loadDist(self::FILE), PromptFiles::DIR.'/'.self::FILE.'.md.dist', true);

        $base = $dist;
        $local = PromptFiles::load(self::FILE, $warnings);
        if (PromptFiles::resolvedPath(self::FILE) === PromptFiles::localPath(self::FILE)) {
            try {
                $base = self::parseBase($local, PromptFiles::DIR.'/'.self::FILE.'.md', false);
            } catch (\RuntimeException $e) {
                $warnings[] = $e->getMessage().' — the config of '.PromptFiles::DIR.'/'.self::FILE.'.md.dist was used.';
            }
        }

        $addition = self::additionPath($promptsDir);
        $override = null;
        if ($addition !== null) {
            $shown = ($promptsDir !== null ? basename(rtrim($promptsDir, '/')) : '').'/'.self::ADDITION_FILE;
            try {
                $override = self::parse((string) @file_get_contents($addition), true, $shown);
            } catch (\RuntimeException $e) {
                $warnings[] = $e->getMessage().' — the config without the addition was used.';
            }
        }
        if ($override !== null && $override !== []) {
            $base['stages'] = $override['stages'] ?? $base['stages'];
            $base['skip_allowed'] = array_replace($base['skip_allowed'], $override['skip_allowed'] ?? []);
        }

        return ['stages' => $base['stages'], 'skip_allowed' => $base['skip_allowed'], 'warnings' => $warnings];
    }

    /**
     * @return array{stages: list<array{id: string, agent: string, required: bool}>, skip_allowed: array<string, bool>}
     */
    private static function parseBase(string $markdown, string $shown, bool $isDist): array
    {
        try {
            $data = self::parse($markdown, false, $shown);
        } catch (\RuntimeException $e) {
            if ($isDist) {
                throw new \RuntimeException($e->getMessage().' — the installation is broken.', 0, $e);
            }
            throw $e;
        }
        assert(isset($data['stages'], $data['skip_allowed']));

        return ['stages' => $data['stages'], 'skip_allowed' => $data['skip_allowed']];
    }

    /**
     * Parse the first ```json block.
     *
     * @param bool $partial an addition: both fields are optional and a missing block gives `[]`;
     *                      otherwise the block is required and both fields must be complete
     *
     * @return array{stages?: list<array{id: string, agent: string, required: bool}>, skip_allowed?: array<string, bool>}
     *
     * @throws \RuntimeException with a human-readable reason
     */
    public static function parse(string $markdown, bool $partial = false, string $shown = 'pm_runbook'): array
    {
        if (preg_match(self::BLOCK_RE, str_replace("\r\n", "\n", $markdown), $m) !== 1) {
            if ($partial) {
                return [];
            }
            throw new \RuntimeException("{$shown} has no ```json block");
        }
        try {
            $data = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$shown}: the ```json block cannot be parsed: ".$e->getMessage());
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException("{$shown}: the ```json block must be an object with stages and skip_allowed");
        }
        $unknown = array_diff(array_keys($data), ['stages', 'skip_allowed']);
        if ($unknown !== []) {
            throw new \RuntimeException("{$shown}: unknown field ".implode(', ', array_map('strval', $unknown)));
        }

        $result = [];
        if (array_key_exists('stages', $data) || !$partial) {
            $result['stages'] = self::stages($data['stages'] ?? null, $shown);
        }
        if (array_key_exists('skip_allowed', $data) || !$partial) {
            $result['skip_allowed'] = self::skipAllowed($data['skip_allowed'] ?? null, !$partial, $shown);
        }

        return $result;
    }

    /**
     * @return list<array{id: string, agent: string, required: bool}>
     */
    private static function stages(mixed $raw, string $shown): array
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw new \RuntimeException("{$shown}: stages must be a non-empty list");
        }
        $stages = [];
        $ids = [];
        foreach ($raw as $i => $stage) {
            $id = is_array($stage) ? ($stage['id'] ?? null) : null;
            $agent = is_array($stage) ? ($stage['agent'] ?? null) : null;
            $required = is_array($stage) ? ($stage['required'] ?? null) : null;
            if (!is_string($id) || preg_match(self::ID_RE, $id) !== 1) {
                throw new \RuntimeException("{$shown}: stages[{$i}].id must be a lowercase identifier");
            }
            if (isset($ids[$id])) {
                throw new \RuntimeException("{$shown}: stage {$id} is listed twice");
            }
            if (!is_string($agent) || trim($agent) === '') {
                throw new \RuntimeException("{$shown}: stages[{$i}].agent must be a non-empty string");
            }
            if (!is_bool($required)) {
                throw new \RuntimeException("{$shown}: stages[{$i}].required must be true or false");
            }
            $ids[$id] = true;
            $stages[] = ['id' => $id, 'agent' => $agent, 'required' => $required];
        }

        return $stages;
    }

    /**
     * @return array<string, bool>
     */
    private static function skipAllowed(mixed $raw, bool $complete, string $shown): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new \RuntimeException("{$shown}: skip_allowed must be an object");
        }
        $skip = [];
        foreach ($raw as $key => $value) {
            if (!in_array($key, self::SKIP_KEYS, true)) {
                throw new \RuntimeException("{$shown}: skip_allowed has an unknown run kind '{$key}'");
            }
            if (!is_bool($value)) {
                throw new \RuntimeException("{$shown}: skip_allowed.{$key} must be true or false");
            }
            $skip[$key] = $value;
        }
        if ($complete) {
            $missing = array_diff(self::SKIP_KEYS, array_keys($skip));
            if ($missing !== []) {
                throw new \RuntimeException("{$shown}: skip_allowed lacks ".implode(', ', $missing));
            }
        }

        return $skip;
    }
}
