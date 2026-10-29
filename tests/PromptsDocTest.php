<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\PromptFiles;
use GpsLab\WorkspaceAssistant\SystemPrompt;
use PHPUnit\Framework\TestCase;

/**
 * Guards the catalog of PROMPTS.md (the first table under `## Catalog`) against drift from `prompts/`
 * and from the kinds defined in the code.
 */
final class PromptsDocTest extends TestCase
{
    public function testRealCatalogMatchesPromptsDirectory(): void
    {
        $root = dirname(__DIR__);
        $doc = (string) file_get_contents($root.'/PROMPTS.md');
        $files = self::listFiles($root.'/prompts');

        self::assertNotSame([], $files);
        self::assertSame([], self::check($doc, $files, PromptFiles::CONTRACT, SystemPrompt::MODES, $root.'/prompts'));
        self::assertStringContainsString(count($files) - 6 .' texts', $doc);
        self::assertStringContainsString('6 built-in agents', $doc);
        self::assertStringContainsString('The '.count(PromptFiles::CONTRACT).' names', $doc);
    }

    public function testWrongPlaceholderIsReported(): void
    {
        $dir = sys_get_temp_dir().'/prompts-doc-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/a.md.dist', 'Hi {problem} and {state}');

        try {
            $ok = "# P\n\n## Catalog\n\n| File | Kind | Placeholders |\n|---|---|---|\n| `a` | setting | `{problem}`, `{state}` |\n";
            self::assertSame([], self::check($ok, ['a'], [], ['pm'], $dir));
            $bad = str_replace('`{problem}`', '`{probelm}`', $ok);
            self::assertSame(
                ['`a` lists placeholders "probelm, state", the file has "problem, state"'],
                self::check($bad, ['a'], [], ['pm'], $dir),
            );
        } finally {
            unlink($dir.'/a.md.dist');
            rmdir($dir);
        }
    }

    public function testRemovedRowIsReported(): void
    {
        $doc = self::doc([['common', 'contract']]);

        self::assertSame(
            ['prompts/common_limits.md.dist has no row in the catalog'],
            self::check($doc, ['common', 'common_limits'], ['common', 'common_limits'], ['pm']),
        );
    }

    public function testNewFileWithoutRowIsReported(): void
    {
        $doc = self::doc([['common', 'contract'], ['role_pm', 'persona']]);

        self::assertSame(
            ['prompts/brand_new.md.dist has no row in the catalog'],
            self::check($doc, ['common', 'role_pm', 'brand_new'], ['common'], ['pm']),
        );
    }

    public function testRowOfMissingFileAndDuplicateAreReported(): void
    {
        $doc = self::doc([['common', 'contract'], ['common', 'contract'], ['ghost', 'setting']]);

        self::assertSame(
            ['`common` has more than one row in the catalog', '`ghost` names no file in prompts/'],
            self::check($doc, ['common'], ['common'], ['pm']),
        );
    }

    public function testContractFileMarkedSettingIsReported(): void
    {
        $doc = self::doc([['common', 'setting'], ['role_pm', 'persona'], ['default_file_prompt', 'setting'], ['agents/critic', 'setting']]);

        self::assertSame(
            ['`common` is marked "setting", the code says "contract"'],
            self::check($doc, ['common', 'role_pm', 'default_file_prompt', 'agents/critic'], ['common'], ['pm']),
        );
    }

    /**
     * @param list<string> $files    names without `.md.dist`
     * @param list<string> $contract
     * @param list<string> $modes
     * @param ?string      $dir      when set, the Placeholders column is compared with the `.dist` files in it
     *
     * @return list<string> problems
     */
    public static function check(string $doc, array $files, array $contract, array $modes, ?string $dir = null): array
    {
        $expected = [];
        foreach ($files as $name) {
            $expected[$name] = match (true) {
                in_array($name, $contract, true) => 'contract',
                $name === 'persona_common', in_array(str_starts_with($name, 'role_') ? substr($name, 5) : null, $modes, true) => 'persona',
                default => 'setting',
            };
        }

        $rows = [];
        $problems = [];
        foreach (self::catalogRows($doc) as [$name, $kind, $listed]) {
            if (isset($rows[$name])) {
                if ($rows[$name] !== false) {
                    $problems[] = '`'.$name.'` has more than one row in the catalog';
                    $rows[$name] = false;
                }
                continue;
            }
            $rows[$name] = $kind;
            if (!isset($expected[$name])) {
                $problems[] = '`'.$name.'` names no file in prompts/';
            } elseif ($kind !== $expected[$name]) {
                $problems[] = '`'.$name.'` is marked "'.$kind.'", the code says "'.$expected[$name].'"';
            }
            if ($dir !== null && $listed !== null && isset($expected[$name])) {
                $actual = PromptFiles::placeholders((string) file_get_contents($dir.'/'.$name.'.md.dist'));
                if ($listed !== $actual) {
                    $problems[] = '`'.$name.'` lists placeholders "'.implode(', ', $listed).'", the file has "'.implode(', ', $actual).'"';
                }
            }
        }
        foreach (array_keys($expected) as $name) {
            if (!isset($rows[$name])) {
                $problems[] = 'prompts/'.$name.'.md.dist has no row in the catalog';
            }
        }

        return $problems;
    }

    /**
     * Rows of the first table under `## Catalog`, up to the next heading.
     *
     * @return list<array{string, string, ?list<string>}> name, kind, placeholders (null: no such column)
     */
    private static function catalogRows(string $doc): array
    {
        $rows = [];
        $inCatalog = false;
        $kindColumn = null;
        $phColumn = null;
        foreach (explode("\n", $doc) as $line) {
            if (preg_match('/^#{1,6}\s/', $line) === 1) {
                if ($inCatalog) {
                    break;
                }
                $inCatalog = trim($line) === '## Catalog';
                continue;
            }
            if (!$inCatalog || !str_starts_with(ltrim($line), '|')) {
                if ($rows !== [] || $kindColumn !== null) {
                    if (trim($line) === '') {
                        break;
                    }
                }
                continue;
            }
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            if ($kindColumn === null) {
                $found = array_search('Kind', $cells, true);
                $kindColumn = $found === false ? -1 : (int) $found;
                $ph = array_search('Placeholders', $cells, true);
                $phColumn = $ph === false ? null : (int) $ph;
                continue;
            }
            if (preg_match('/^:?-+:?$/', $cells[0]) === 1 || $kindColumn < 0) {
                continue;
            }
            $name = trim($cells[0], '`');
            $listed = null;
            if ($phColumn !== null) {
                $listed = array_values(array_filter(array_map(
                    static fn (string $p): string => trim($p, ' `{}'),
                    explode(',', $cells[$phColumn] ?? ''),
                ), static fn (string $p): bool => $p !== ''));
                sort($listed);
            }
            $rows[] = [$name, $cells[$kindColumn] ?? '', $listed];
        }

        return $rows;
    }

    /**
     * @param list<array{string, string}> $rows
     */
    private static function doc(array $rows): string
    {
        $doc = "# Prompts\n\n## Catalog\n\nIntro.\n\n| File | Kind | Used in |\n|---|---|---|\n";
        foreach ($rows as [$name, $kind]) {
            $doc .= '| `'.$name.'` | '.$kind." | somewhere |\n";
        }

        return $doc."\n## Assembly\n\n| `other` | setting | x |\n";
    }

    /**
     * @return list<string>
     */
    private static function listFiles(string $dir): array
    {
        $names = [];
        foreach (['', 'agents/'] as $sub) {
            foreach (glob($dir.'/'.$sub.'*.md.dist') ?: [] as $path) {
                $names[] = $sub.basename($path, '.md.dist');
            }
        }

        return $names;
    }
}
