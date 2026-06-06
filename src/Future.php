<?php

declare(strict_types=1);

namespace Switon\Executor;

use Throwable;

/**
 * Immutable task result returned by executor operations.
 *
 * Use as the default `FutureInterface` implementation for batch execution results.
 *
 * @see \Switon\Executor\FutureInterface
 */
class Future implements FutureInterface
{
    protected function __construct(
        protected bool       $successful,
        protected mixed      $result = null,
        protected ?Throwable $error = null,
    ) {
    }

    /**
     * Create a successful future.
     */
    public static function success(mixed $result = null): self
    {
        return new self(true, $result);
    }

    /**
     * Create a failed future.
     */
    public static function failure(Throwable $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * {@inheritDoc}
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * {@inheritDoc}
     */
    public function result(): mixed
    {
        return $this->result;
    }

    /**
     * {@inheritDoc}
     */
    public function error(): ?Throwable
    {
        return $this->error;
    }
}
