<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests\Coder;

use GpsLab\WorkspaceAssistant\Coder\IntakeApi;
use GpsLab\WorkspaceAssistant\Coder\IntakeConfig;
use GpsLab\WorkspaceAssistant\Coder\IntakeController;
use GpsLab\WorkspaceAssistant\Coder\Routing;
use GpsLab\WorkspaceAssistant\Coder\Store;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

final class FakeIntakeApi implements IntakeApi
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $issues = [];
    /** @var array<string, list<array<string, mixed>>> */
    public array $events = [];
    /** @var array<string, true> */
    public array $fail = [];
    /** @var list<array{kind:string, repository:string, page:int, timeout:int}> */
    public array $calls = [];
    /** @var \Closure(string):void|null */
    public ?\Closure $onCall = null;

    public function openIssues(string $repository, int $page, int $perPage, int $timeoutSeconds): array
    {
        $this->calls[] = ['kind' => 'issues', 'repository' => $repository, 'page' => $page, 'timeout' => $timeoutSeconds];
        if ($this->onCall !== null) {
            ($this->onCall)($repository);
        }
        if (isset($this->fail[$repository])) {
            throw new \RuntimeException($repository === 'slow/repo' ? 'request timeout' : 'read failed');
        }

        return $page === 1 ? array_slice($this->issues[$repository] ?? [], 0, $perPage) : [];
    }

    public function issueEvents(string $repository, int $issueNumber, int $timeoutSeconds): array
    {
        $this->calls[] = ['kind' => 'events', 'repository' => $repository, 'page' => $issueNumber, 'timeout' => $timeoutSeconds];
        if ($this->onCall !== null) {
            ($this->onCall)($repository);
        }

        return $this->events[$repository.'#'.$issueNumber] ?? [];
    }
}

#[CoversClass(IntakeController::class)]
#[CoversClass(IntakeConfig::class)]
#[CoversClass(Routing::class)]
#[CoversClass(Store::class)]
final class IntakeControllerTest extends TestCase
{
    private string $dir;
    private Store $store;
    private FakeIntakeApi $api;
    private float $now = 100.0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/coder-intake-'.uniqid('', true);
        $this->store = new Store($this->dir);
        $this->api = new FakeIntakeApi();
    }

    protected function tearDown(): void
    {
        $this->store->disconnect();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @param array<string, string> $routing */
    private function controller(array $routing, int $work = 4, int $budget = 2, int $timeout = 1): IntakeController
    {
        return new IntakeController(
            new IntakeConfig(new Routing($routing), 'peter', 5, $budget, $work, $timeout),
            $this->store,
            $this->api,
            fn (): float => $this->now,
        );
    }

    /** @return array<string, mixed> */
    private static function issue(int $number, bool $doIt = true, string $state = 'open', bool $pr = false): array
    {
        return [
            'number' => $number,
            'state' => $state,
            'title' => 'Task '.$number,
            'body' => 'Implement task '.$number,
            'html_url' => 'https://example.test/issue/'.$number,
            'labels' => $doIt ? [['name' => 'openronin:do-it']] : [],
        ] + ($pr ? ['pull_request' => ['url' => 'https://example.test/pr/'.$number]] : []);
    }

    /** @return array<string, mixed> */
    private static function approval(int $id, string $actor = 'peter'): array
    {
        return ['id' => $id, 'event' => 'labeled', 'actor' => ['login' => $actor], 'label' => ['name' => 'openronin:do-it']];
    }

    public function testEmptyDefaultAllowlistCreatesNoJobsAndStartsNoWorker(): void
    {
        $this->api->issues['acme/one'] = [self::issue(1)];
        $this->api->events['acme/one#1'] = [self::approval(91)];

        self::assertSame(0, $this->controller([])->poll());
        self::assertSame([], $this->api->calls);
        self::assertSame([], $this->store->jobs());
        self::assertSame([], $this->store->workers());
        self::assertNull($this->controller([])->pollDueAt());
    }

    public function testOnlyTrustedAuthorLabelEventsInExplicitlyRoutedRepositoriesAreAdmitted(): void
    {
        $this->api->issues['acme/allowed'] = [
            self::issue(1),
            self::issue(2),
            self::issue(3),
            self::issue(4, false),
            self::issue(5, state: 'closed'),
            self::issue(6, pr: true),
            self::issue(7, false),
            self::issue(8),
        ];
        $this->api->events = [
            'acme/allowed#1' => [self::approval(9001)],
            'acme/allowed#2' => [self::approval(9002, 'stranger')],
            'acme/allowed#3' => [],
            'acme/allowed#4' => [self::approval(9004)],
            'acme/allowed#5' => [self::approval(9005)],
            'acme/allowed#6' => [self::approval(9006)],
            'acme/allowed#7' => [self::approval(9007)],
            'acme/allowed#8' => [self::approval(9008), self::approval(9009, 'stranger')],
            'acme/open#10' => [self::approval(9010)],
            'acme/unlisted#11' => [self::approval(9011)],
        ];
        $this->api->issues['acme/open'] = [self::issue(10)];
        $this->api->issues['acme/unlisted'] = [self::issue(11)];

        $examined = $this->controller(['acme/allowed' => 'coder', 'acme/open' => 'openronin'], work: 20)->poll();

        self::assertSame(8, $examined);
        self::assertCount(1, $this->store->jobs());
        $job = $this->store->jobs()[0];
        self::assertSame('acme/allowed', $job['repository']);
        self::assertSame(1, $job['issue_number']);
        self::assertSame('9001', $job['diagnostics']['label_event_id']);
        self::assertSame('peter', $job['diagnostics']['approved_by']);
        self::assertStringContainsString('Implement task 1', $job['diagnostics']['prompt']);
        self::assertSame(['standalone-issue:1:label-event:9001'], $this->store->processedEventKeys($job['id']));
        self::assertSame(['issues', 'events', 'events', 'events', 'events'], array_column($this->api->calls, 'kind'));
        self::assertNotContains('acme/open', array_column($this->api->calls, 'repository'));
        self::assertNotContains('acme/unlisted', array_column($this->api->calls, 'repository'));
    }

    public function testAFullEventPageCannotProveTheCurrentLabelAndFailsClosed(): void
    {
        $this->api->issues['acme/one'] = [self::issue(1)];
        $this->api->events['acme/one#1'] = [
            self::approval(1),
            ...array_fill(0, 99, ['id' => 2, 'event' => 'mentioned', 'actor' => ['login' => 'stranger']]),
        ];

        self::assertSame(1, $this->controller(['acme/one' => 'coder'])->poll());
        self::assertSame([], $this->store->jobs());
    }

    public function testWorkBudgetAlsoBoundsEmptyRepositoryScans(): void
    {
        $controller = $this->controller(['acme/a' => 'coder', 'acme/b' => 'coder', 'acme/c' => 'coder'], work: 2);

        self::assertSame(0, $controller->poll());
        self::assertSame(['acme/a', 'acme/b'], array_column($this->api->calls, 'repository'));
        $this->now += 5;
        self::assertSame(0, $controller->poll());
        self::assertSame(['acme/a', 'acme/b', 'acme/c', 'acme/a'], array_column($this->api->calls, 'repository'));
    }

    public function testRepeatedPollsCannotDuplicateAnIntakeJob(): void
    {
        $this->api->issues['acme/one'] = [self::issue(1)];
        $this->api->events['acme/one#1'] = [self::approval(42)];
        $controller = $this->controller(['acme/one' => 'coder']);

        self::assertSame(1, $controller->poll());
        $this->now += 5;
        self::assertSame(1, $controller->poll());

        self::assertCount(1, $this->store->jobs());
        self::assertCount(1, $this->store->processedEventKeys($this->store->jobs()[0]['id']));
    }

    public function testWorkBudgetAndDurableRoundRobinCursorPreserveProgressAcrossRepositories(): void
    {
        foreach (['acme/a', 'acme/b', 'acme/c'] as $repository) {
            $this->api->issues[$repository] = [self::issue(1), self::issue(2)];
            $this->api->events[$repository.'#1'] = [self::approval(1001)];
            $this->api->events[$repository.'#2'] = [self::approval(1002)];
        }
        $routing = ['acme/a' => 'coder', 'acme/b' => 'coder', 'acme/c' => 'coder'];

        $first = $this->controller($routing, work: 1);
        self::assertSame(1, $first->poll());
        self::assertSame(['acme/a'], array_column($this->store->jobs(), 'repository'));
        self::assertSame(['page' => 1, 'offset' => 1], $this->store->intakeCursor('acme/a'));
        self::assertSame('acme/a', $this->store->intakeSchedule());

        // A new controller resumes the durable schedule: the worker owns no second cursor.
        $this->now += 5;
        $second = $this->controller($routing, work: 1);
        self::assertSame(1, $second->poll());
        $this->now += 5;
        self::assertSame(1, $second->poll());
        self::assertSame(['acme/a', 'acme/b', 'acme/c'], array_column($this->store->jobs(), 'repository'));

        $this->now += 5;
        self::assertSame(1, $second->poll());
        self::assertSame(['acme/a', 'acme/b', 'acme/c', 'acme/a'], array_column($this->store->jobs(), 'repository'));
        self::assertSame([1, 1, 1, 2], array_column($this->store->jobs(), 'issue_number'));
        $this->now += 5;
        self::assertSame(1, $second->poll());
        self::assertSame(['acme/a', 'acme/b', 'acme/c', 'acme/a', 'acme/b'], array_column($this->store->jobs(), 'repository'));
        self::assertSame([1, 1, 1, 2, 2], array_column($this->store->jobs(), 'issue_number'));
    }

    public function testTimeBudgetStopsBeforeAnotherRequestAndPassesTheConfiguredTimeout(): void
    {
        $this->api->issues['acme/one'] = [self::issue(1), self::issue(2), self::issue(3)];
        foreach ([1, 2, 3] as $number) {
            $this->api->events['acme/one#'.$number] = [self::approval(500 + $number)];
        }
        $this->api->onCall = function (): void {
            $this->now += 0.4;
        };

        $this->controller(['acme/one' => 'coder'], budget: 2, timeout: 1)->poll();

        self::assertSame(['issues', 'events', 'events'], array_column($this->api->calls, 'kind'));
        self::assertSame([1, 1, 1], array_column($this->api->calls, 'timeout'));
        self::assertSame([1, 2], array_column($this->store->jobs(), 'issue_number'));
        self::assertSame(['page' => 1, 'offset' => 2], $this->store->intakeCursor('acme/one'));
    }

    public function testFailureIsIsolatedAndASlowRepositoryDoesNotStarveTheNextRepository(): void
    {
        $this->api->issues['acme/a'] = [self::issue(1)];
        $this->api->issues['acme/b'] = [self::issue(2)];
        $this->api->events['acme/b#2'] = [self::approval(82)];
        $this->api->fail['acme/a'] = true;
        $controller = $this->controller(['acme/a' => 'coder', 'acme/b' => 'coder']);

        self::assertSame(1, $controller->poll());
        self::assertSame([2], array_column($this->store->jobs(), 'issue_number'));

        // A request that consumes its own timeout ends this pass; round-robin gives the next pass to B.
        $this->api->fail = ['slow/repo' => true];
        $this->api->issues['slow/repo'] = [self::issue(3)];
        $this->api->issues['acme/b'][] = self::issue(3);
        $this->api->events['acme/b#3'] = [self::approval(83)];
        $this->api->onCall = function (string $repository): void {
            if ($repository === 'slow/repo') {
                $this->now += 3;
            }
        };
        $this->now += 5;
        $slow = $this->controller(['slow/repo' => 'coder', 'acme/b' => 'coder']);
        self::assertSame(0, $slow->poll());
        $this->api->onCall = null;
        $this->now += 5;
        self::assertSame(2, $slow->poll());
        self::assertSame([2, 3], array_column($this->store->jobs(), 'issue_number'));
        self::assertCount(2, $this->store->jobs(), 'Repeated intake of B is deduplicated after the slow repository.');
    }
}
