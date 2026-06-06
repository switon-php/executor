<?php

declare(strict_types=1);

namespace Switon\Executor\Tests\Unit;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ClockInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\RunnerInterface;
use Switon\Executor\Event\RunnerFinished;
use Switon\Executor\Event\RunnerStarting;
use Switon\Executor\Exception\InvalidTaskException;
use Switon\Executor\Exception\TaskRunnerException;
use Switon\Executor\Executor;
use Switon\Executor\FutureInterface;
use Switon\Executor\GateInterface;
use Switon\Executor\Tests\TestCase;
use Throwable;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;

class ExecutorTest extends TestCase
{
    public function testInvokeAllReturnsEmptyListForEmptyInput(): void
    {
        $executor = $this->makeExecutor($this->createStub(ContainerInterface::class));

        $this->assertSame([], $executor->invokeAll([]));
    }

    public function testInvokeReturnsResultFromRunner(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner('done'));

        $executor = $this->makeExecutor($container);

        $this->assertSame('done', $executor->invoke('svc', ['k' => 1]));
    }

    public function testInvokeUsesServiceIdBeforeColon(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('get')->with('Acme')->willReturn(new EchoRunner('ok'));

        $executor = $this->makeExecutor($container);

        $this->assertSame('ok', $executor->invoke('Acme:single', ['k' => 1]));
    }

    public function testInvokeThrowsWhenTaskIdEmpty(): void
    {
        $this->expectException(InvalidTaskException::class);

        $executor = $this->makeExecutor($this->createStub(ContainerInterface::class));
        $executor->invoke('', null);
    }

    public function testInvokeThrowsWhenTaskStartsWithColon(): void
    {
        $this->expectException(InvalidTaskException::class);

        $executor = $this->makeExecutor($this->createStub(ContainerInterface::class));
        $executor->invoke(':job-1', ['k' => 1]);
    }

    public function testInvokeDispatchesStartedAndFinishedEvents(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner('done'));

        $dispatcher = new RecordingDispatcher();
        $executor = $this->makeExecutor($container, $dispatcher);

        $this->assertSame('done', $executor->invoke('svc', ['k' => 1]));
        $this->assertCount(2, $dispatcher->events);
        $this->assertInstanceOf(RunnerStarting::class, $dispatcher->events[0]);
        $this->assertInstanceOf(RunnerFinished::class, $dispatcher->events[1]);
    }

    public function testInvokeDispatchesFinishedEventWhenRunnerThrows(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner(error: new RuntimeException('boom')));

        $dispatcher = new RecordingDispatcher();
        $executor = $this->makeExecutor($container, $dispatcher);

        $this->expectException(RuntimeException::class);
        try {
            $executor->invoke('svc', ['k' => 1]);
        } finally {
            $this->assertCount(2, $dispatcher->events);
            $this->assertInstanceOf(RunnerFinished::class, $dispatcher->events[1]);
            $this->assertInstanceOf(RuntimeException::class, $dispatcher->events[1]->error);
        }
    }

    public function testInvokeAllReturnsSuccessFuturesInOrder(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static function (string $id): EchoRunner {
            return match ($id) {
                'a' => new EchoRunner('A'),
                'b' => new EchoRunner('B'),
                default => throw new LogicException('unexpected service ' . $id),
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
    }

    public function testInvokeAllWrapsRunnerExceptionAsFailedFuture(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner(error: new RuntimeException('job failed')));

        $executor = $this->makeExecutor($container);
        /** @var list<FutureInterface> $futures */
        $futures = $executor->invokeAll([['svc', null]]);

        $this->assertFalse($futures[0]->isSuccessful());
        $this->assertInstanceOf(RuntimeException::class, $futures[0]->error());
        $this->assertSame('job failed', $futures[0]->error()->getMessage());
    }

    public function testInvokeAllUsesServiceIdBeforeColon(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('get')->with('Acme')->willReturn(new EchoRunner('ok'));

        $executor = $this->makeExecutor($container);
        $futures = $executor->invokeAll([['Acme:batch-1', 'payload']]);

        $this->assertSame('ok', $futures[0]->result());
    }

    public function testInvokeAllUsesServiceIdBeforeColonEvenWhenSuffixEmpty(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('get')->with('Acme')->willReturn(new EchoRunner('ok'));

        $executor = $this->makeExecutor($container);
        $futures = $executor->invokeAll([['Acme:', 'payload']]);

        $this->assertTrue($futures[0]->isSuccessful());
        $this->assertSame('ok', $futures[0]->result());
    }

    public function testInvokeAllFailureWhenServiceIsNotRunner(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new stdClass());

        $executor = $this->makeExecutor($container);
        $futures = $executor->invokeAll([['bad', null]]);

        $this->assertFalse($futures[0]->isSuccessful());
        $this->assertInstanceOf(TaskRunnerException::class, $futures[0]->error());
    }

    public function testInvokeAnyReturnsFirstSuccessfulResult(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['x', new EchoRunner('first')],
        ]);

        $executor = $this->makeExecutor($container);

        $this->assertSame('first', $executor->invokeAny([['x', null]]));
    }

    public function testInvokeAnySkipsFailures(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static function (string $id): EchoRunner {
            return match ($id) {
                'bad' => new EchoRunner(error: new RuntimeException('no')),
                'good' => new EchoRunner('yes'),
                default => throw new LogicException('unexpected service ' . $id),
            };
        });

        $executor = $this->makeExecutor($container);

        $this->assertSame('yes', $executor->invokeAny([['bad', null], ['good', null]]));
    }

    public function testInvokeAnyReturnsNullWhenAllFail(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner(error: new RuntimeException('x')));

        $executor = $this->makeExecutor($container);

        $this->assertNull($executor->invokeAny([['a', null], ['b', null]]));
    }

    public function testInvokeAnyReturnsNullForEmptyList(): void
    {
        $executor = $this->makeExecutor($this->createStub(ContainerInterface::class));

        $this->assertNull($executor->invokeAny([]));
    }

    public function testInvokeAnySkipsInvalidTaskDefinitionAndContinues(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with('good')
            ->willReturn(new EchoRunner('ok'));

        $executor = $this->makeExecutor($container);

        $this->assertSame('ok', $executor->invokeAny([
            ['', null],
            ['good', ['id' => 1]],
        ]));
    }

    public function testSubmitRunsSynchronouslyWhenCoroutineDisabled(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner('done'));

        $dispatcher = new RecordingDispatcher();
        $executor = $this->makeExecutor($container, $dispatcher);

        $executor->submit('svc', ['k' => 1]);

        $this->assertCount(2, $dispatcher->events);
        $this->assertInstanceOf(RunnerStarting::class, $dispatcher->events[0]);
        $this->assertSame('svc', $dispatcher->events[0]->task);
        $this->assertSame(['k' => 1], $dispatcher->events[0]->payload);
        $this->assertInstanceOf(RunnerFinished::class, $dispatcher->events[1]);
        $this->assertSame('done', $dispatcher->events[1]->result);
        $this->assertNull($dispatcher->events[1]->error);
    }

    public function testFinishedEventCarriesErrorWhenRunnerThrows(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner(error: new InvalidArgumentException('bad arg')));

        $dispatcher = new RecordingDispatcher();
        $executor = $this->makeExecutor($container, $dispatcher);

        $futures = $executor->invokeAll([['svc', null]]);

        $this->assertFalse($futures[0]->isSuccessful());
        $this->assertCount(2, $dispatcher->events);
        $finished = $dispatcher->events[1];
        $this->assertInstanceOf(RunnerFinished::class, $finished);
        $this->assertInstanceOf(InvalidArgumentException::class, $finished->error);
    }

    public function testInvokeAllFailureWhenTaskIdEmpty(): void
    {
        $executor = $this->makeExecutor($this->createStub(ContainerInterface::class));
        $futures = $executor->invokeAll([['', null]]);

        $this->assertFalse($futures[0]->isSuccessful());
        $this->assertInstanceOf(InvalidTaskException::class, $futures[0]->error());
    }

    public function testInvokeAllFailureWhenTaskStartsWithColon(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        $executor = $this->makeExecutor($container);
        $futures = $executor->invokeAll([[":job-1", ['k' => 1]]]);

        $this->assertFalse($futures[0]->isSuccessful());
        $this->assertInstanceOf(InvalidTaskException::class, $futures[0]->error());
    }

    public function testInvokeAllContinuesAfterInvalidTaskAndRunsNextTask(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with('good')
            ->willReturn(new EchoRunner('done'));

        $executor = $this->makeExecutor($container);
        $futures = $executor->invokeAll([
            [':bad', null],
            ['good', ['id' => 1]],
        ]);

        $this->assertCount(2, $futures);
        $this->assertFalse($futures[0]->isSuccessful());
        $this->assertInstanceOf(InvalidTaskException::class, $futures[0]->error());
        $this->assertTrue($futures[1]->isSuccessful());
        $this->assertSame('done', $futures[1]->result());
    }

    public function testFinishedEventCarriesNullResultAndElapsedWhenRunnerThrows(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new EchoRunner(error: new InvalidArgumentException('bad arg')));

        $dispatcher = new RecordingDispatcher();
        $executor = $this->makeExecutor($container, $dispatcher);

        $futures = $executor->invokeAll([['svc', ['k' => 1]]]);

        $this->assertFalse($futures[0]->isSuccessful());
        $this->assertCount(2, $dispatcher->events);
        $this->assertInstanceOf(RunnerStarting::class, $dispatcher->events[0]);

        $finished = $dispatcher->events[1];
        $this->assertInstanceOf(RunnerFinished::class, $finished);
        $this->assertSame('svc', $finished->task);
        $this->assertSame(['k' => 1], $finished->payload);
        $this->assertNull($finished->result);
        $this->assertInstanceOf(InvalidArgumentException::class, $finished->error);
        $this->assertSame(0.01, $finished->elapsed);
    }

    protected function makeExecutor(
        ContainerInterface   $container,
        ?RecordingDispatcher $dispatcher = null,
        ?GateInterface       $gate = null,
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
            'eventDispatcher' => $dispatcher ?? new RecordingDispatcher(),
            'gate' => $gate ?? $this->createStub(GateInterface::class),
        ]);
    }
}

final class EchoRunner implements RunnerInterface
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

final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
