<?php

declare(strict_types=1);

namespace Switon\Executor\Tests\Unit;

use RuntimeException;
use Switon\Executor\Future;
use Switon\Executor\Tests\TestCase;

class FutureTest extends TestCase
{
    public function testSuccessCarriesResult(): void
    {
        $f = Future::success(42);

        $this->assertTrue($f->isSuccessful());
        $this->assertSame(42, $f->result());
        $this->assertNull($f->error());
    }

    public function testFailureCarriesError(): void
    {
        $e = new RuntimeException('boom');
        $f = Future::failure($e);

        $this->assertFalse($f->isSuccessful());
        $this->assertNull($f->result());
        $this->assertSame($e, $f->error());
    }
}
