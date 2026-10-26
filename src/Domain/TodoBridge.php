<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * Read-only view of wp-plugin-todo: its projects (to link one to a project of the list) and their open tasks.
 * Todo keeps its own projects – the list only points to one (the `todoProject` field). Without wp-plugin-todo
 * everything here is empty.
 */
final class TodoBridge
{
    private const PRIORITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2, 'none' => 3];

    /** @var array<int, array{id: int, name: string, color: string, url: string}>|null */
    private ?array $projects = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $tasks = null;

    public static function available(): bool
    {
        return class_exists(\MKTodo\Domain\TaskRepository::class) && class_exists(\MKTodo\App\AppShell::class);
    }

    /** @return array<int, array{id: int, name: string, color: string, url: string}> Todo's projects, in its order */
    public function projects(): array
    {
        if ($this->projects === null) {
            $this->projects = [];

            if (self::available()) {
                foreach ($this->repository()->all() as $project) {
                    $this->projects[$project['id']] = [
                        'id' => $project['id'],
                        'name' => $project['name'],
                        'color' => $project['color'],
                        'url' => self::url($project['id']),
                    ];
                }
            }
        }

        return $this->projects;
    }

    /**
     * The linked Todo project with its open and overdue task counts, or null when there is none (anymore).
     *
     * @return array{id: int, name: string, color: string, url: string, open: int, overdue: int}|null
     */
    public function summary(?int $id): ?array
    {
        $project = $id ? ($this->projects()[$id] ?? null) : null;

        if (!$project) {
            return null;
        }

        $open = $this->openTasks($id);
        $today = current_time('Y-m-d');

        return $project + [
            'open' => count($open),
            'overdue' => count(array_filter($open, static fn (array $task): bool => $task['due'] !== null && $task['due'] < $today)),
        ];
    }

    /**
     * Open tasks of a Todo project – dated ones first (day, time), then by priority, then the oldest.
     *
     * @return array<int, array{id: int, title: string, status: string, start: string|null, due: string|null, time: string|null, priority: string}>
     */
    public function openTasks(int $id): array
    {
        $tasks = array_values(array_filter($this->tasks(), static fn (array $task): bool => $task['projectId'] === $id && !$task['done']));

        usort($tasks, static fn (array $a, array $b): int => strcmp($a['due'] ?? '9999', $b['due'] ?? '9999')
            ?: strcmp($a['time'] ?? '99', $b['time'] ?? '99')
            ?: (self::PRIORITY_RANK[$a['priority']] ?? 3) <=> (self::PRIORITY_RANK[$b['priority']] ?? 3)
            ?: strcmp((string) $a['createdAt'], (string) $b['createdAt']));

        return array_map(static fn (array $task): array => array_intersect_key($task, array_flip(['id', 'title', 'status', 'start', 'due', 'time', 'priority'])), $tasks);
    }

    public static function url(int $id): string
    {
        return \MKTodo\App\AppShell::url() . 'project/' . $id;
    }

    /** @return array<int, array<string, mixed>> */
    private function tasks(): array
    {
        if ($this->tasks === null) {
            $this->tasks = self::available() ? (new \MKTodo\Domain\TaskRepository($this->repository()))->all() : [];
        }

        return $this->tasks;
    }

    private function repository(): \MKTodo\Domain\ProjectRepository
    {
        return new \MKTodo\Domain\ProjectRepository(new \MKTodo\Domain\AreaRepository());
    }
}
