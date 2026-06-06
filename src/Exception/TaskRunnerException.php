<?php

declare(strict_types=1);

namespace Switon\Executor\Exception;

use Switon\Executor\Exception as BaseException;

/**
 * Use when executor task dispatch cannot resolve a valid runner service.
 */
class TaskRunnerException extends BaseException
{
}
