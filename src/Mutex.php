<?php

declare(strict_types=1);

namespace Hibla\Sync;

use function Hibla\async;

use Hibla\Promise\Interfaces\PromiseInterface;

use Hibla\Promise\Promise;

class Mutex
{
    /**
     * Whether the mutex is currently locked.
     */
    private bool $locked = false;

    /**
     * @var array<int, Promise<$this>>
     */
    private array $queue = [];

    public function __construct()
    {
    }

    /**
     * @return PromiseInterface<$this>
     */
    public function acquire(): PromiseInterface
    {
        if (! $this->locked) {
            $this->locked = true;

            return Promise::resolved($this);
        }

        // Mutex is locked, create a pending promise and queue it
        /** @var Promise<$this> $promise */
        $promise = new Promise();
        $id = spl_object_id($promise);
        $this->queue[$id] = $promise;

        $promise->onCancel(function () use ($id): void {
            unset($this->queue[$id]);
        });

        return $promise;
    }

    /**
     * @throws \LogicException If release() is called when not locked
     */
    public function release(): void
    {
        if (! $this->locked) {
            throw new \LogicException('Cannot release a mutex that is not locked');
        }

        if (\count($this->queue) === 0) {
            $this->locked = false;

            return;
        }

        $id = array_key_first($this->queue);
        $promise = $this->queue[$id];
        unset($this->queue[$id]);

        $promise->resolve($this);
    }

    /**
     * Acquire the lock, execute the callable in a fiber, then release automatically.
     * The callable runs inside async() — use await() freely inside it.
     * Guarantees release on fulfillment, rejection, or cancellation.
     *
     * @template TReturn
     * @param callable(): TReturn $callable
     * @return PromiseInterface<TReturn>
     */
    public function withLock(callable $callable): PromiseInterface
    {
        return $this->acquire()->then(function () use ($callable) {
            $inner = async($callable);

            $inner->then(
                onFulfilled: $this->release(...),
                onRejected: $this->release(...)
            );

            $inner->onCancel($this->release(...));

            return $inner;
        });
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function getQueueLength(): int
    {
        return \count($this->queue);
    }

    public function isQueueEmpty(): bool
    {
        return \count($this->queue) === 0;
    }
}
