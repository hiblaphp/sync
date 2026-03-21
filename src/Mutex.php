<?php

declare(strict_types=1);

namespace Hibla\Sync;

use function Hibla\async;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

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
class Mutex
{
    private bool $locked = false;

    /**
     * @var array<int, Promise<$this>>
     */
    private array $queue = [];

    public function __construct() {}

    /**
     * Acquire the mutex lock.
     *
     * If the mutex is not currently locked, it is immediately acquired and
     * a resolved promise containing this instance is returned.
     *
     * If the mutex is already locked, a pending promise is created, added
     * to the FIFO queue, and returned. The promise resolves with this
     * instance when the lock becomes available.
     *
     * Cancelling the returned promise removes it from the queue immediately
     * without affecting the lock state or skipping other waiters.
     *
     * @return PromiseInterface<$this> Promise that resolves with this mutex
     *                                 instance when the lock is acquired.
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
     * Release the mutex lock.
     *
     * If no fibers are waiting, the mutex is unlocked and marked available
     * for immediate acquisition.
     *
     * If fibers are waiting, ownership transfers directly to the next promise
     * in the queue — the mutex stays locked the entire time. The next waiter's
     * promise is resolved with this instance.
     *
     * Only the fiber that currently holds the lock should call release().
     * Calling release() without holding the lock throws immediately.
     *
     * @throws \LogicException If called when the mutex is not locked.
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
     *
     * This is the preferred API over acquire() and release(). Release is
     * guaranteed on all outcomes — fulfillment, rejection, and cancellation —
     * with no try/finally boilerplate required from the caller.
     *
     * The callable runs inside async() implicitly. Use await() freely inside
     * it — the lock remains held across all suspension points until the
     * callable completes or throws:
     *
     * ```php
     * await($mutex->withLock(function () {
     *     $user   = await(fetchUser(1));    // lock held across this await
     *     $orders = await(fetchOrders(1));  // and this one
     *     return processOrders($user, $orders);
     * }));
     * ```
     *
     * Cancelling the promise returned by withLock() releases the lock
     * immediately and cancels the in-flight callable.
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
     * Returns true if the mutex is currently locked.
     *
     * Useful for debugging and monitoring. Do not use this for control flow —
     * the lock state can change between checking and acting on the result.
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Returns the number of promises currently waiting to acquire the lock.
     */
    public function getQueueLength(): int
    {
        return \count($this->queue);
    }

    /**
     * Returns true if no promises are waiting to acquire the lock.
     */
    public function isQueueEmpty(): bool
    {
        return \count($this->queue) === 0;
    }
}