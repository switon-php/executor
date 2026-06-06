<?php

declare(strict_types=1);

namespace Switon\Executor;

/**
 * Execute container-registered runners by task id.
 *
 * Use when application code needs one shared execution surface for fire-and-forget work,
 * wait-all batches, or first-success selection without depending on runtime-specific APIs.
 *
 * Guidance: Pass `list<array{0: string, 1?: mixed}>` task tuples exactly; malformed entries are caller bugs and are not normalized by executor implementations.
 *
 * Road-signs:
 * - task id maps to one RunnerInterface service
 * - single-task sync execution: invoke
 * - background work: submit
 * - batch results: invokeAll / FutureInterface
 * - wait for first success: invokeAny
 * - lifecycle hooks: RunnerStarting / RunnerFinished
 *
 * `task` is the execution key seen by callers.
 * Implementations may resolve the runner service from the part before `:`,
 * while the full task string remains available for concurrency control.
 *
 * Grouped calls keep input order for returned futures.
 * `invokeAny()` returns the first successful result and otherwise returns `null`.
 * Task ids may use `serviceId:suffix`; implementations may route by the part before `:`.
 *
 * @see \Switon\Core\RunnerInterface
 * @see \Switon\Executor\FutureInterface
 * @see \Switon\Executor\Event\RunnerStarting
 * @see \Switon\Executor\Event\RunnerFinished
 */
interface ExecutorInterface
{
    /**
     * Execute one task and wait for its result.
     *
     * Use this when the caller needs the runner result in the same flow.
     * `task` is the concurrency-control id as a whole string.
     * When `task` contains `:`, executor implementations may use the part before `:` as container service id.
     * Executor implementations should emit one runner-starting event before calling <code>RunnerInterface::run()</code>
     * and one runner-finished event after that call returns or throws.
     *
     * @param string $task Container service id, or `serviceId:suffix` when one executable needs isolated concurrency buckets.
     *
     * @return mixed Runner result
     */
    public function invoke(string $task, mixed $payload = null): mixed;

    /**
     * Submit one task for background execution.
     *
     * Use this when the caller does not need a result in the same flow.
     * `task` is the concurrency-control id as a whole string.
     * When `task` contains `:`, executor implementations may use the part before `:` as container service id.
     * Executor implementations should emit one runner-starting event before calling <code>RunnerInterface::run()</code>
     * and one runner-finished event after that call returns or throws.
     *
     * @param string $task Container service id, or `serviceId:suffix` when one executable needs isolated concurrency buckets.
     */
    public function submit(string $task, mixed $payload = null, int $concurrency = 1): void;

    /**
     * Execute a task list and wait until all tasks finish.
     *
     * Use this when each input needs an outcome record and input order must be preserved.
     * `tasks` must already satisfy the declared tuple shape; malformed entries are treated as caller bugs.
     * The whole task string stays as the concurrency-control id.
     * Implementations may resolve the executable by taking the part before `:` as container service id.
     * Each resolved runner call should emit its own runner-starting and runner-finished events.
     *
     * @param list<array{0: string, 1?: mixed}> $tasks
     *
     * @return list<FutureInterface>
     */
    public function invokeAll(array $tasks): array;

    /**
     * Execute a task list and return the first successful task result.
     *
     * Use this when the caller accepts the earliest successful result and can ignore failed attempts.
     * `tasks` must already satisfy the declared tuple shape; malformed entries are treated as caller bugs.
     * The whole task string stays as the concurrency-control id.
     * Implementations may resolve the executable by taking the part before `:` as container service id.
     * Each attempted runner call should emit its own runner-starting and runner-finished events.
     *
     * @param list<array{0: string, 1?: mixed}> $tasks
     *
     * @return mixed First successful runner result, or null when all attempts fail
     */
    public function invokeAny(array $tasks): mixed;
}
