<?php

declare(strict_types=1);

namespace Switon\Executor\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ClockInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\RunnerInterface;
use Switon\Core\Runtime;
use Switon\Executor\Event\RunnerFinished;
use Switon\Executor\Event\RunnerStarting;
use Switon\Executor\Exception\TaskSchedulingException;
use Switon\Executor\Executor;
use Switon\Executor\FutureInterface;
use Switon\Executor\GateInterface;
use Switon\Executor\Tests\TestCase;
use Swoole\Coroutine;
use Throwable;
use LogicException;
use RuntimeException;

/**
 * Executor Swoole/coroutine paths ({@see Runtime::isCoroutineEnabled()}).
 */
#[RequiresPhpExtension('swoole')]
class ExecutorCoroutineTest extends TestCase
{
    public function testInvokeAllParallelPreservesFuturesOrder(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willReturnCallback(static function (string $id): TaggedEchoRunner {
                    return match ($id) {
                        'a' => new TaggedEchoRunner('A'),
                        'b' => new TaggedEchoRunner('B'),
                        default => throw new LogicException('unexpected ' . $id),
                    };
                });

                $executor = $this->makeExecutor($container);
                $futures = $executor->invokeAll([
                    ['a', null],
                    ['b', null],
                ]);

                $this->assertCount(2, $futures);
                $this->assertTrue($futures[0]->isSuccessful());
                $this->assertSame('A', $futures[0]->result());
                $this->assertTrue($futures[1]->isSuccessful());
                $this->assertSame('B', $futures[1]->result());
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testInvokeAnyReturnsFirstSuccessAcrossCoroutines(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willReturnCallback(static function (string $id): TaggedEchoRunner {
                    return match ($id) {
                        'bad' => new TaggedEchoRunner(null, new RuntimeException('no')),
                        'good' => new TaggedEchoRunner('yes'),
                        default => throw new LogicException('unexpected ' . $id),
                    };
                });

                $executor = $this->makeExecutor($container);

                $this->assertSame(
                    'yes',
                    $executor->invokeAny([['bad', null], ['good', null]])
                );
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testInvokeAnyReturnsNullWhenAllFailInCoroutineMode(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willReturn(
                    new TaggedEchoRunner(null, new RuntimeException('x'))
                );

                $executor = $this->makeExecutor($container);

                $this->assertNull($executor->invokeAny([['a', null], ['b', null]]));
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testSubmitUsesGateWhenCoroutineEnabled(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willReturn(new TaggedEchoRunner('async'));

                $dispatcher = new CoroutineRecordingDispatcher();
                $gate = new RecordingGate();
                $executor = $this->makeExecutor($container, $dispatcher, $gate);

                $executor->submit('svc', ['k' => 1], 1);

                $deadline = microtime(true) + 2.0;
                while (count($dispatcher->events) < 2 && microtime(true) < $deadline) {
                    Coroutine::sleep(0.001);
                }

                $this->assertCount(2, $dispatcher->events);
                $this->assertInstanceOf(RunnerStarting::class, $dispatcher->events[0]);
                $this->assertSame('svc', $dispatcher->events[0]->task);
                $this->assertInstanceOf(RunnerFinished::class, $dispatcher->events[1]);
                $this->assertSame('async', $dispatcher->events[1]->result);
                $this->assertSame([
                    ['method' => 'acquire', 'task' => 'svc', 'concurrency' => 1],
                    ['method' => 'release', 'task' => 'svc'],
                ], $gate->calls);
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testInvokeRunsRunnerInCoroutineMode(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willReturn(new TaggedEchoRunner('sync'));

                $executor = $this->makeExecutor($container);

                $this->assertSame('sync', $executor->invoke('svc', ['k' => 1]));
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testSubmitThrowsWhenCoroutineSchedulingFails(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            set_error_handler(static function (): bool {
                return true;
            });

            try {
                \Swoole\Coroutine::set(['max_coroutine' => 1]);

                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willReturn(new TaggedEchoRunner('unused'));

                $executor = $this->makeExecutor($container);

                $caught = null;

                try {
                    $executor->submit('svc', ['k' => 1], 1);
                } catch (TaskSchedulingException $exception) {
                    $caught = $exception;
                }

                $this->assertInstanceOf(TaskSchedulingException::class, $caught);
            } finally {
                restore_error_handler();
                Runtime::setCoroutineEnabled(false);
                \Swoole\Coroutine::set(['max_coroutine' => 100000]);
            }
        });
    }

    /**
     * @param list<array{0: string, 1?: mixed}> $tasks
     */
    public function testInvokeAllParallelWrapsFailuresPerIndex(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willReturnCallback(static function (string $id): TaggedEchoRunner {
                    return match ($id) {
                        'ok' => new TaggedEchoRunner('fine'),
                        'boom' => new TaggedEchoRunner(null, new RuntimeException('parallel fail')),
                        default => throw new LogicException('unexpected ' . $id),
                    };
                });

                $executor = $this->makeExecutor($container);
                /** @var list<FutureInterface> $futures */
                $futures = $executor->invokeAll([
                    ['ok', null],
                    ['boom', null],
                ]);

                $this->assertTrue($futures[0]->isSuccessful());
                $this->assertSame('fine', $futures[0]->result());
                $this->assertFalse($futures[1]->isSuccessful());
                $this->assertSame('parallel fail', $futures[1]->error()->getMessage());
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testInvokeAllParallelRecordsSchedulingFailurePerIndex(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            set_error_handler(static function (): bool {
                return true;
            });

            try {
                \Swoole\Coroutine::set(['max_coroutine' => 1]);

                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willThrowException(
                    new LogicException('runner lookup should not be reached')
                );

                $executor = $this->makeExecutor($container);
                /** @var list<FutureInterface> $futures */
                $futures = $executor->invokeAll([['svc', null]]);

                $this->assertCount(1, $futures);
                $this->assertFalse($futures[0]->isSuccessful());
                $this->assertInstanceOf(TaskSchedulingException::class, $futures[0]->error());
            } finally {
                restore_error_handler();
                Runtime::setCoroutineEnabled(false);
                \Swoole\Coroutine::set(['max_coroutine' => 100000]);
            }
        });
    }

    public function testInvokeAnyReturnsNullWhenSchedulingFailsInCoroutineMode(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            set_error_handler(static function (): bool {
                return true;
            });

            try {
                \Swoole\Coroutine::set(['max_coroutine' => 1]);

                $container = $this->createStub(ContainerInterface::class);
                $container->method('get')->willThrowException(
                    new LogicException('runner lookup should not be reached')
                );

                $executor = $this->makeExecutor($container);

                $this->assertNull($executor->invokeAny([['svc', null]]));
            } finally {
                restore_error_handler();
                Runtime::setCoroutineEnabled(false);
                \Swoole\Coroutine::set(['max_coroutine' => 100000]);
            }
        });
    }

    protected function makeExecutor(
        ContainerInterface        $container,
        ?EventDispatcherInterface $dispatcher = null,
        ?GateInterface            $gate = null,
    ): Executor {
        $clock = $this->createStub(ClockInterface::class);
        $time = 1_000_000.0;
        $clock->method('microtime')->willReturnCallback(function () use (&$time): float {
            $time += 0.01;

            return $time;
        });

        return $this->make(Executor::class, [
            'container' => $container,
            'clock' => $clock,
            'eventDispatcher' => $dispatcher ?? new CoroutineRecordingDispatcher(),
            'gate' => $gate ?? $this->createStub(GateInterface::class),
        ]);
    }
}

final class CoroutineRecordingDispatcher implements \Psr\EventDispatcher\EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}

final class RecordingGate implements GateInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function acquire(string $task, ?int $concurrency): void
    {
        $this->calls[] = [
            'method' => 'acquire',
            'task' => $task,
            'concurrency' => $concurrency,
        ];
    }

    public function release(string $task): void
    {
        $this->calls[] = [
            'method' => 'release',
            'task' => $task,
        ];
    }
}

final class TaggedEchoRunner implements RunnerInterface
{
    public function __construct(
        private mixed      $return = null,
        private ?Throwable $error = null,
    ) {
    }

    public function run(mixed $payload): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->return ?? $payload;
    }
}
