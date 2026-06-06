<?php

declare(strict_types=1);

namespace Switon\Executor\Exception;

use Switon\Executor\Exception as BaseException;

/**
 * Use when executor task ids or task batches are invalid for runtime dispatch.
 */
class InvalidTaskException extends BaseException
{
}
