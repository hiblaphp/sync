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
 * Common use cases:
 * - Protecting shared mutable state from concurrent modification
 * - Serializing writes to a resource that doesn't support concurrent access
 * - Guarding initialization logic that must only run once
 *
 * The event loop continues running freely while fibers are waiting — only
 * fibers competing for this specific mutex are held back. A mutex only
 * limits access when multiple fibers share the same instance. Each
 * instance manages an independent lock.
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
 *     $data = await(readSharedState());
 *     await(writeSharedState($data + 1));
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
     * @inheritDoc
     */
    public int $queueLength {
        get => \count($this->queue);
    }

    private bool $locked = false;

    /**
     * @var array<int, Promise<$this>>
     */
    private array $queue = [];

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
            // Lock was never granted — no state to restore.
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
            // No waiters — mark the mutex as available for immediate acquisition.
            $this->locked = false;

            return;
        }

        // Transfer ownership directly to the next waiter without unlocking.
        // The mutex stays locked the entire time — no window for a new
        // acquisition to jump the queue between release and the next resolve.
        $id = array_key_first($this->queue);
        $promise = $this->queue[$id];
        unset($this->queue[$id]);

        $promise->resolve($this);
    }

    /**
     * @inheritDoc
     */
    public function withLock(callable $callable): PromiseInterface
    {
        return $this->acquire()->then(function () use ($callable) {
            $inner = async($callable);

            $inner->then(
                onFulfilled: function (): void {
                    $this->release();
                },
                onRejected: function (): void {
                    $this->release();
                }
            );

            $inner->onCancel(function (): void {
                $this->release();
            });

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
    public function isQueueEmpty(): bool
    {
        return count($this->queue) === 0;
    }
}
