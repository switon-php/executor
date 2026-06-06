<?php

declare(strict_types=1);

namespace Switon\Executor;

use Throwable;

/**
 * Completed task result handle returned by executor invocations.
 *
 * Use this when callers need a stable success/error wrapper for ordered batch results.
 *
 * @see \Switon\Executor\ExecutorInterface
 */
interface FutureInterface
{
    /**
     * Report whether task execution finished successfully.
     *
     * @return bool True when the runner completed without throwing
     */
    public function isSuccessful(): bool;

    /**
     * Return task result when execution succeeded.
     *
     * @return mixed Successful runner result
     */
    public function result(): mixed;

    /**
     * Return task error when execution failed.
     *
     * @return Throwable|null Runner error or null on success
     */
    public function error(): ?Throwable;
}
