<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

use GpsLab\WorkspaceAssistant\Database;
use GpsLab\WorkspaceAssistant\ForgeOwners;

/** Keeps epic execution and standalone intake from consuming one repository. */
final readonly class RoutingGuard
{
    public function __construct(
        private Routing $routing,
        private Database $db,
        private ForgeOwners $owners,
    ) {
    }

    /** A running epic conflicts at startup; a blocked one stays stopped and its resume is guarded separately. */
    public function assertRoutingAllowed(): void
    {
        $coderRepositories = $this->routing->coderRepositories();
        if ($coderRepositories === []) {
            return;
        }
        foreach ($this->db->epicsBlockingRouting() as $epic) {
            foreach ($this->planRepositories($this->db->epicTasks($epic['slug']), $this->db->epicReleases($epic['slug'])) as $repository) {
                if ($this->routing->isCoderRouted($repository)) {
                    throw new \RuntimeException("Cannot route {$repository} to coder: epic {$epic['slug']} is {$epic['status']}; pause or complete it first.");
                }
            }
        }
    }

    /**
     * @param list<array{task:string}> $tasks
     * @param list<array{repo:string}> $releases
     */
    public function assertPlanAllowed(array $tasks, array $releases): void
    {
        foreach ($this->planRepositories($tasks, $releases) as $repository) {
            if ($this->routing->isCoderRouted($repository)) {
                throw new \InvalidArgumentException("Epic plan cannot include {$repository}: the repository is routed to the standalone coder.");
            }
        }
    }

    /** Canonical owner/repository when this task belongs to the standalone coder, null otherwise. */
    public function coderRepository(string $task): ?string
    {
        $repository = $this->owners->slug($task);

        return $this->routing->isCoderRouted($repository) ? $repository : null;
    }

    /**
     * @param list<array{task:string}> $tasks
     * @param list<array{repo:string}> $releases
     *
     * @return list<string>
     */
    private function planRepositories(array $tasks, array $releases): array
    {
        $references = array_merge(array_column($tasks, 'task'), array_column($releases, 'repo'));
        $repositories = [];
        foreach ($references as $reference) {
            $repositories[] = $this->owners->slug($reference);
        }

        return array_values(array_unique($repositories));
    }
}
