<?php

declare(strict_types=1);

namespace Switon\Executor\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted immediately before executor calls <code>RunnerInterface::run()</code> once.
 *
 * Log category: <code>switon.executor.runner.starting</code>
 *
 * @see \Switon\Executor\Executor::runTask()
 * @see \Switon\Executor\Event\RunnerFinished
 */
#[EventLevel(Severity::DEBUG)]
class RunnerStarting implements JsonSerializable
{
    public function __construct(
        public string $task,
        public mixed  $payload = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'task' => $this->task,
        ];
    }
}
