<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Keyboards;
use PHPUnit\Framework\TestCase;

final class KeyboardsTest extends TestCase
{
    public function testModeMarksActiveModeAndEncodesThread(): void
    {
        $keyboard = Keyboards::mode('review', 4323);

        self::assertFalse($keyboard->skipped);
        self::assertSame(['inline_keyboard' => [[
            ['text' => '🧠 arch', 'callback_data' => 'mode:arch:4323'],
            ['text' => '🔍 review ✓', 'callback_data' => 'mode:review:4323'],
            ['text' => '📋 pm', 'callback_data' => 'mode:pm:4323'],
        ]]], $keyboard->markup);
    }

    public function testModeMarksArchAsActiveByDefault(): void
    {
        self::assertSame('🧠 arch ✓', Keyboards::mode('arch', 0)->markup['inline_keyboard'][0][0]['text']);
    }

    public function testLanguageChunksByThreeAndMarksCurrent(): void
    {
        $locales = [];
        foreach (['en', 'ru', 'de', 'fr'] as $code) {
            $locales[] = ['code' => $code, 'label' => strtoupper($code)];
        }

        $keyboard = Keyboards::language($locales, 'ru');

        self::assertFalse($keyboard->skipped);
        self::assertSame(['inline_keyboard' => [
            [
                ['text' => 'EN', 'callback_data' => 'lang:en'],
                ['text' => 'RU ✓', 'callback_data' => 'lang:ru'],
                ['text' => 'DE', 'callback_data' => 'lang:de'],
            ],
            [['text' => 'FR', 'callback_data' => 'lang:fr']],
        ]], $keyboard->markup);
    }

    public function testLanguageSkipsCodeOverTheLimitButKeepsExactFit(): void
    {
        $fits = str_repeat('a', 64 - strlen('lang:'));
        $keyboard = Keyboards::language([
            ['code' => $fits, 'label' => 'fits'],
            ['code' => $fits.'b', 'label' => 'long'],
        ], 'en');

        self::assertTrue($keyboard->skipped);
        self::assertSame([[['text' => 'fits', 'callback_data' => "lang:{$fits}"]]], $keyboard->markup['inline_keyboard']);
    }

    public function testLanguageMeasuresBytesNotCharacters(): void
    {
        $code = str_repeat('я', 30); // 30 characters, 60 bytes: 65 with the prefix

        $keyboard = Keyboards::language([['code' => $code, 'label' => 'x']], 'en');

        self::assertTrue($keyboard->skipped);
        self::assertTrue($keyboard->isEmpty());
    }

    public function testEpicMenuDropsOnlyOversizedActions(): void
    {
        $long = 'epic:pause:1:'.str_repeat('s', 52); // 65 bytes
        $exact = 'epic:pause:1:'.str_repeat('s', 51); // 64 bytes

        $keyboard = Keyboards::epicMenu([['⏸ pause', $long], ['📊 status', $exact]]);

        self::assertTrue($keyboard->skipped);
        self::assertSame(['inline_keyboard' => [[['text' => '📊 status', 'callback_data' => $exact]]]], $keyboard->markup);
    }

    public function testEpicMenuWithoutActionsIsEmpty(): void
    {
        $keyboard = Keyboards::epicMenu([]);

        self::assertFalse($keyboard->skipped);
        self::assertTrue($keyboard->isEmpty());
    }

    public function testEpicCandidatesListsSlugsAndCancel(): void
    {
        $keyboard = Keyboards::epicCandidates(7, ['a', 'b'], 10, 'Cancel');

        self::assertFalse($keyboard->skipped);
        self::assertSame(['inline_keyboard' => [
            [['text' => 'a', 'callback_data' => 'epic:pick:7:a']],
            [['text' => 'b', 'callback_data' => 'epic:pick:7:b']],
            [['text' => 'Cancel', 'callback_data' => 'epic:cancel:7']],
        ]], $keyboard->markup);
    }

    public function testEpicCandidatesCapsTheListButOnlyFittingOnesCount(): void
    {
        $prefix = 'epic:pick:7:';
        $fit = str_repeat('x', 64 - strlen($prefix));
        $long = $fit.'y';

        $keyboard = Keyboards::epicCandidates(7, [$long, $fit, 'a', 'b'], 2, 'Cancel');

        self::assertTrue($keyboard->skipped);
        self::assertSame(
            [$fit, 'a', 'Cancel'],
            array_column(array_merge(...$keyboard->markup['inline_keyboard']), 'text'),
        );
    }

    public function testEpicCandidatesAllSkippedGivesEmptyKeyboardWithoutCancel(): void
    {
        $keyboard = Keyboards::epicCandidates(7, [str_repeat('x', 60)], 10, 'Cancel');

        self::assertTrue($keyboard->skipped);
        self::assertTrue($keyboard->isEmpty());
        self::assertSame(['inline_keyboard' => []], $keyboard->markup);
    }

    public function testEpicConfirmation(): void
    {
        $keyboard = Keyboards::epicConfirmation(7, 'plan', 'Start', 'Cancel');

        self::assertFalse($keyboard->skipped);
        self::assertSame(['inline_keyboard' => [[
            ['text' => 'Start', 'callback_data' => 'epic:go:7:plan'],
            ['text' => 'Cancel', 'callback_data' => 'epic:cancel:7'],
        ]]], $keyboard->markup);
    }

    public function testEpicConfirmationSkipsOversizedStart(): void
    {
        $keyboard = Keyboards::epicConfirmation(7, str_repeat('x', 60), 'Start', 'Cancel');

        self::assertTrue($keyboard->skipped);
        self::assertSame(['inline_keyboard' => [[['text' => 'Cancel', 'callback_data' => 'epic:cancel:7']]]], $keyboard->markup);
    }

    public function testDecision(): void
    {
        $keyboard = Keyboards::decision('Commit', 'Discard');

        self::assertFalse($keyboard->skipped);
        self::assertSame(['inline_keyboard' => [[
            ['text' => 'Commit', 'callback_data' => 'commit'],
            ['text' => 'Discard', 'callback_data' => 'discard'],
        ]]], $keyboard->markup);
    }

    public function testRemove(): void
    {
        $keyboard = Keyboards::remove();

        self::assertFalse($keyboard->skipped);
        self::assertSame(['inline_keyboard' => []], $keyboard->markup);
    }
}
