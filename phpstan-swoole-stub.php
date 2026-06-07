<?php

declare(strict_types=1);

namespace Swoole {
    if (!class_exists(Coroutine::class, false)) {
        class Coroutine
        {
            /**
             * @param callable(): void $func
             */
            public static function create(callable $func): bool
            {
                return true;
            }
        }
    }
}

namespace Swoole\Coroutine {
    if (!class_exists(Channel::class, false)) {
        class Channel
        {
            public function __construct(int $size = 1)
            {
            }

            public function push(mixed $data): bool
            {
                return true;
            }

            public function pop(): mixed
            {
                return null;
            }
        }
    }

    if (!class_exists(WaitGroup::class, false)) {
        class WaitGroup
        {
            public function __construct()
            {
            }

            public function add(int $count = 1): void
            {
            }

            public function done(): void
            {
            }

            public function wait(): void
            {
            }
        }
    }
}
