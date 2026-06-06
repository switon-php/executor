<?php

declare(strict_types=1);

namespace Switon\Executor;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\RunnerInterface;
use Switon\Core\Runtime;
use Switon\Executor\Event\RunnerFinished;
use Switon\Executor\Event\RunnerStarting;
use Switon\Executor\Exception\InvalidTaskException;
use Switon\Executor\Exception\TaskRunnerException;
use Switon\Executor\Exception\TaskSchedulingException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Throwable;

use function array_fill;
use function count;
use function explode;
use function is_string;
use function round;
use function str_contains;

/**
 * Auto-detecting executor runtime backed by container-resolved runners.
 *
 * Use when runner execution needs lifecycle events, task grouping, and optional coroutine concurrency gates.
 *
 * Road-signs:
 * - runner lookup: RunnerInterface
 * - runner lifecycle events: RunnerStarting / RunnerFinished
 * - async gate state: gates
 * - result handle: Future
 *
 * @see \Switon\Executor\ExecutorInterface
 * @see \Switon\Core\RunnerInterface
 * @see \Switon\Executor\Future
 * @see \Switon\Executor\Event\RunnerStarting
 * @see \Switon\Executor\Event\RunnerFinished
 */
class Executor implements ExecutorInterface
{
    #[Autowired] protected ContainerInterface $container;

    #[Autowired] protected ClockInterface $clock;

    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    #[Autowired] protected GateInterface $gate;

    /**
     * {@inheritDoc}
     */
    public function invoke(string $task, mixed $payload = null): mixed
    {
        $runnerId = str_contains($task, ':') ? explode(':', $task, 2)[0] : $task;

        if ($runnerId === '') {
            InvalidTaskException::raise('Task id must start with a runner service id.');
        }

        $runner = $this->container->get($runnerId);

        if (!$runner instanceof RunnerInterface) {
            TaskRunnerException::raise(
                'Task {task} resolved service {service}, but it does not implement {interface}.',
                [
                    'task' => $task,
                    'service' => $runnerId,
                    'interface' => RunnerInterface::class,
                ]
            );
        }

        $startedAt = $this->clock->microtime();
        $this->eventDispatcher->dispatch(new RunnerStarting($task, $payload));
        $result = null;
        $error = null;

        try {
            $result = $runner->run($payload);
        } catch (Throwable $exception) {
            $error = $exception;
            throw $exception;
        } finally {
            $this->eventDispatcher->dispatch(
                new RunnerFinished(
                    $task,
                    $payload,
                    $result,
                    $error,
                    round($this->clock->microtime() - $startedAt, 3)
                )
            );
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function submit(string $task, mixed $payload = null, int $concurrency = 1): void
    {
        if (Runtime::isCoroutineEnabled()) {
            $scheduled = Coroutine::create(function () use ($task, $payload, $concurrency): void {
                $this->gate->acquire($task, $concurrency);
                try {
                    $this->invoke($task, $payload);
                } finally {
                    $this->gate->release($task);
                }
            });

            if ($scheduled === false) {
                TaskSchedulingException::raise('Swoole refused to schedule task {task}.', ['task' => $task]);
            }

            return;
        }

        $this->invoke($task, $payload);
    }

    /**
     * {@inheritDoc}
     */
    public function invokeAll(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        if (Runtime::isCoroutineEnabled()) {
            return $this->invokeAllWithSwoole($tasks);
        }

        return $this->invokeAllSerial($tasks);
    }

    /**
     * @param list<array{0: string, 1?: mixed}> $tasks
     */
    public function invokeAny(array $tasks): mixed
    {
        if (Runtime::isCoroutineEnabled()) {
            return $this->invokeAnyWithSwoole($tasks);
        }

        foreach ($tasks as $taskDefinition) {
            try {
                $task = $taskDefinition[0] ?? null;

                if (!is_string($task) || $task === '') {
                    InvalidTaskException::raise('Task definition must start with a non-empty task id.');
                }

                return $this->invoke($task, $taskDefinition[1] ?? null);
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * @param list<array{0: string, 1?: mixed}> $tasks
     *
     * @return list<FutureInterface>
     */
    protected function invokeAllSerial(array $tasks): array
    {
        $futures = [];

        foreach ($tasks as $taskDefinition) {
            try {
                $futures[] = Future::success($this->invoke($taskDefinition[0], $taskDefinition[1] ?? null));
            } catch (Throwable $error) {
                $futures[] = Future::failure($error);
            }
        }

        return $futures;
    }

    /**
     * @param list<array{0: string, 1?: mixed}> $tasks
     *
     * @return list<FutureInterface>
     */
    protected function invokeAllWithSwoole(array $tasks): array
    {
        $futures = array_fill(0, count($tasks), null);
        $waitGroup = new WaitGroup();

        foreach ($tasks as $index => $taskDefinition) {
            $waitGroup->add();
            $scheduled = Coroutine::create(function () use (&$futures, $waitGroup, $index, $taskDefinition): void {
                try {
                    try {
                        $futures[$index] = Future::success($this->invoke($taskDefinition[0], $taskDefinition[1] ?? null));
                    } catch (Throwable $error) {
                        $futures[$index] = Future::failure($error);
                    }
                } finally {
                    $waitGroup->done();
                }
            });

            if ($scheduled === false) {
                $futures[$index] = Future::failure(
                    TaskSchedulingException::of('Swoole refused to schedule invokeAll task at index {index}.', ['index' => $index])
                );
                $waitGroup->done();
            }
        }

        $waitGroup->wait();

        /** @var list<FutureInterface> $futures */
        return $futures;
    }

    /**
     * @param list<array{0: string, 1?: mixed}> $tasks
     */
    protected function invokeAnyWithSwoole(array $tasks): mixed
    {
        $results = new Channel(count($tasks));

        foreach ($tasks as $index => $taskDefinition) {
            $scheduled = Coroutine::create(function () use ($results, $taskDefinition): void {
                try {
                    $results->push([
                        'successful' => true,
                        'result' => $this->invoke($taskDefinition[0], $taskDefinition[1] ?? null),
                    ]);
                } catch (Throwable $error) {
                    $results->push([
                        'successful' => false,
                        'error' => $error,
                    ]);
                }
            });

            if ($scheduled === false) {
                $results->push(
                    [
                        'successful' => false,
                        'error' => TaskSchedulingException::of(
                            'Swoole refused to schedule invokeAny task at index {index}.',
                            ['index' => $index]
                        ),
                    ]
                );
            }
        }

        for ($i = 0, $total = count($tasks); $i < $total; $i++) {
            $message = $results->pop();

            if ($message['successful']) {
                return $message['result'];
            }
        }

        return null;
    }

}
