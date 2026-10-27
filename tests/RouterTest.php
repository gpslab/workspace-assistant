<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\Command\Command;
use GpsLab\WorkspaceAssistant\Command\CommandRequest;
use GpsLab\WorkspaceAssistant\Command\Router;
use GpsLab\WorkspaceAssistant\Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    private FakeTelegram $tg;
    private Router $router;

    /** @var list<array{string, CommandRequest}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->tg = new FakeTelegram();
        $this->router = new Router($this->tg, new Locale());
        $this->router->register($this->recording('help', ['start']));
        $this->router->register($this->recording('mode', []));
    }

    /**
     * @param list<string> $aliases
     */
    private function recording(string $name, array $aliases): Command
    {
        return new class($name, $aliases, function (string $name, CommandRequest $request): void {
            $this->calls[] = [$name, $request];
        }) implements Command {
            /**
             * @param list<string> $aliases
             */
            public function __construct(private string $name, private array $aliases, private \Closure $record)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function emoji(): string
            {
                return '*';
            }

            public function aliases(): array
            {
                return $this->aliases;
            }

            public function execute(CommandRequest $request): void
            {
                ($this->record)($this->name, $request);
            }
        };
    }

    public function testAliasRunsTheCommand(): void
    {
        $this->router->handle(1, 0, '/start');

        self::assertCount(1, $this->calls);
        self::assertSame('help', $this->calls[0][0]);
        self::assertSame([], $this->tg->sent);
    }

    public function testBotSuffixIsStrippedAndCaseIgnoredAndArgumentKept(): void
    {
        $this->router->handle(1, 0, '/Help@SomeBot x y');
        $this->router->handle(1, 0, '/MODE@Bot review');

        self::assertSame('help', $this->calls[0][0]);
        self::assertSame('x y', $this->calls[0][1]->argument);
        self::assertSame('mode', $this->calls[1][0]);
        self::assertSame('review', $this->calls[1][1]->argument);
    }

    public function testRequestFieldsArriveUnchanged(): void
    {
        $this->router->handle(11, 22, '/mode arg', 33, 44);

        $request = $this->calls[0][1];
        self::assertSame(11, $request->chatId);
        self::assertSame(22, $request->threadId);
        self::assertSame('arg', $request->argument);
        self::assertSame(33, $request->messageId);
        self::assertSame(44, $request->userId);
    }

    public function testUnknownCommandIsReported(): void
    {
        $this->router->handle(1, 5, '/nope@Bot');

        self::assertSame([], $this->calls);
        self::assertCount(1, $this->tg->sent);
        self::assertStringContainsString('/nope', $this->tg->sent[0]['text']);
    }
}
