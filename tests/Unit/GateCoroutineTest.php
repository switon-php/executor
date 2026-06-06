<?php

declare(strict_types=1);

namespace Switon\Executor\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Switon\Executor\Gate;
use Switon\Executor\Tests\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * Gate behaviour when concurrency limiting uses Swoole channels (requires ext-swoole).
 */
#[RequiresPhpExtension('swoole')]
class GateCoroutineTest extends TestCase
{
    public function testConcurrencyOneSerializesAcquireAcrossCoroutines(): void
    {
        $order = [];

        \Swoole\Coroutine\run(static function () use (&$order): void {
            $gate = new Gate();
            $wg = new WaitGroup();
            $wg->add(2);

            Coroutine::create(static function () use ($gate, &$order, $wg): void {
                $gate->acquire('job', 1);
                $order[] = 1;
                Coroutine::sleep(0.02);
                $gate->release('job');
                $wg->done();
            });

            Coroutine::create(static function () use ($gate, &$order, $wg): void {
                $gate->acquire('job', 1);
                $order[] = 2;
                $gate->release('job');
                $wg->done();
            });

            $wg->wait();
        });

        $this->assertSame([1, 2], $order);
    }

}
