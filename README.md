# Switon Executor Package

[![Executor CI](https://img.shields.io/github/actions/workflow/status/switon-php/executor/ci.yml?branch=main&label=Executor%20CI)](https://github.com/switon-php/executor/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's execution layer for container-registered runners, ordered batch results, first-success selection, and runner
lifecycle events.

## Highlights

- **Task routing:** task ids resolve to the right runner service.
- **Batch execution:** `invokeAll()` keeps results in input order.
- **First-success mode:** `invokeAny()` returns the first successful result.
- **Lifecycle visibility:** `RunnerStarting` and `RunnerFinished` expose run data.
- **Async coordination:** `Future` and `Gate` support ordered task handling.

## Installation

```bash
composer require switon/executor
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Executor\ExecutorInterface;
use Switon\Executor\FutureInterface;

final class ReportRunner implements RunnerInterface
{
    public function run(mixed $payload): array
    {
        return ['id' => $payload['id']];
    }
}

final class ReportService
{
    #[Autowired] protected ExecutorInterface $executor;

    public function export(): array
    {
        $futures = $this->executor->invokeAll([
            ['report:daily', ['id' => 1]],
            ['report:weekly', ['id' => 2]],
        ]);

        return array_map(static fn (FutureInterface $future) => $future->result(), $futures);
    }

    public function fallback(): mixed
    {
        return $this->executor->invokeAny([
            ['report:primary', ['id' => 1]],
            ['report:backup', ['id' => 1]],
        ]);
    }
}
```

Docs: https://docs.switon.dev/latest/executor

## License

MIT.
