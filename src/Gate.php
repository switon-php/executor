<?php

declare(strict_types=1);

namespace Switon\Executor;

use Swoole\Coroutine\Channel;

/**
 * Internal task gate registry backed by coroutine channels.
 *
 * Use this to cap concurrent coroutine submissions per task id.
 *
 * @see \Switon\Executor\GateInterface
 */
class Gate implements GateInterface
{
    /**
     * @var array<string, Channel> Task id to concurrency channel.
     */
    protected array $channels = [];

    /**
     * @var array<string, int> Task id to active submissions sharing one channel.
     */
    protected array $users = [];

    /**
     * {@inheritDoc}
     */
    public function acquire(string $task, ?int $concurrency): void
    {
        if ($concurrency === null || $concurrency < 1) {
            return;
        }

        $channel = $this->channels[$task] ?? null;

        if ($channel === null) {
            $channel = new Channel($concurrency);

            for ($i = 0; $i < $concurrency; $i++) {
                $channel->push(true);
            }

            $this->channels[$task] = $channel;
            $this->users[$task] = 0;
        }

        $this->users[$task]++;
        $channel->pop();
    }

    /**
     * {@inheritDoc}
     */
    public function release(string $task): void
    {
        $channel = $this->channels[$task] ?? null;

        if ($channel === null) {
            return;
        }

        $channel->push(true);

        $users = ($this->users[$task] ?? 1) - 1;

        if ($users <= 0) {
            unset($this->users[$task], $this->channels[$task]);
            return;
        }

        $this->users[$task] = $users;
    }
}
