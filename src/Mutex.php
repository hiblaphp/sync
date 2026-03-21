<?php

declare(strict_types=1);

namespace Hibla\Sync;

use function Hibla\async;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sync\Interfaces\MutexInterface;

/**
 * A mutual exclusion lock for coordinating access to shared state in async PHP.
 *
 * Ensures that only one fiber runs a critical section at a time. All other
 * fibers that attempt to acquire the lock are queued in FIFO order and
 * released one at a time as the lock is released.
 *
 * The event loop continues running freely while fibers are waiting — only
 * fibers competing for this specific mutex are held back. A mutex only
 * serializes access when multiple fibers share the same instance. Each
 * instance is an independent lock.
 *
 * Designed for use with hiblaphp/async. The withLock() helper runs its
 * callable inside async() implicitly, so await() works freely inside it
 * and the critical section reads like ordinary synchronous PHP.
 *
 * Basic usage:
 * ```php
 * $mutex = new Mutex();
 *
 * await($mutex->withLock(function () {
 *     $balance = await($account->getBalance());
 *     await($account->deduct($amount));
 * }));
 * ```
 *
 * Manual usage when fine-grained control is needed:
 * ```php
 * $lock = await($mutex->acquire());
 * try {
 *     await(doWork());
 * } finally {
 *     $lock->release();
 * }
 * ```
 */
class Mutex implements MutexInterface
{
    /**
     * @var array<int, Promise<$this>>
     */
    private array $queue = [];

    private bool $locked = false;

    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function acquire(): PromiseInterface
    {
        if (! $this->locked) {
            $this->locked = true;

            return Promise::resolved($this);
        }

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
     * @inheritDoc
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
     * @inheritDoc
     *
     * @template TReturn
     * @param  callable(): TReturn  $callable  The callable to execute inside the lock.
     *                                          Use await() freely — it runs in a fiber.
     * @return PromiseInterface<TReturn> Promise that resolves with the callable's
     *                                   return value once the lock is released.
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

    /**
     * @inheritDoc
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * @inheritDoc
     */
    public function getQueueLength(): int
    {
        return \count($this->queue);
    }

    /**
     * @inheritDoc
     */
    public function isQueueEmpty(): bool
    {
        return \count($this->queue) === 0;
    }
}
