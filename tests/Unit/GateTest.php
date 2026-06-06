<?php

declare(strict_types=1);

namespace Switon\Executor\Tests\Unit;

use Switon\Executor\Gate;
use Switon\Executor\Tests\TestCase;

class GateTest extends TestCase
{
    public function testAcquireIsNoOpWhenConcurrencyNull(): void
    {
        $gate = new Gate();
        $gate->acquire('task-a', null);
        $gate->release('task-a');

        $this->assertTrue(true);
    }

    public function testAcquireIsNoOpWhenConcurrencyBelowOne(): void
    {
        $gate = new Gate();
        $gate->acquire('task-b', 0);
        $gate->release('task-b');

        $this->assertTrue(true);
    }

    public function testReleaseIsNoOpWhenChannelMissing(): void
    {
        $gate = new Gate();
        $gate->release('unknown');

        $this->assertTrue(true);
    }
}
