<?php

declare(strict_types=1);

namespace Switon\Executor\Exception;

use Switon\Executor\Exception as BaseException;

/**
 * Use when the executor runtime cannot schedule a task for execution.
 */
class TaskSchedulingException extends BaseException
{
}
