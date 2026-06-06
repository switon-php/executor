<?php

declare(strict_types=1);

namespace Switon\Executor;

/**
 * Internal gate registry for task-scoped concurrency control.
 *
 * Use this when coroutine scheduling needs per-task concurrency limits.
 */
interface GateInterface
{
    /**
     * Acquire one gate slot for a task.
     */
    public function acquire(string $task, ?int $concurrency): void;

    /**
     * Release one gate slot for a task.
     */
    public function release(string $task): void;
}
