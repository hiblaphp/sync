<?php

declare(strict_types=1);

namespace Hibla\Sync\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * A mutual exclusion lock for coordinating access to shared state in async PHP.
 *
 * Ensures that only one fiber runs a critical section at a time. All other
 * fibers that attempt to acquire the lock are queued in FIFO order and
 * released one at a time as the lock is released.
 *
 * Designed for use with hiblaphp/async. The withLock() helper runs its
 * callable inside async() implicitly, so await() works freely inside it
 * and the critical section reads like ordinary synchronous PHP.
 */
interface MutexInterface
{
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
    public function acquire(): PromiseInterface;

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
    public function release(): void;

    /**
     * Acquire the lock, execute the callable in a fiber, then release automatically.
     *
     * This is the preferred API over acquire() and release(). Release is
     * guaranteed on all outcomes — fulfillment, rejection, and cancellation —
     * with no try/finally boilerplate required from the caller.
     *
     * The callable runs inside async() implicitly. Use await() freely inside
     * it — the lock remains held across all suspension points until the
     * callable completes or throws.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callable
     * @return PromiseInterface<TReturn>
     */
    public function withLock(callable $callable): PromiseInterface;

    /**
     * Returns true if the mutex is currently locked.
     *
     * Useful for debugging and monitoring. Do not use this for control flow —
     * the lock state can change between checking and acting on the result.
     */
    public function isLocked(): bool;

    /**
     * Returns the number of promises currently waiting to acquire the lock.
     */
    public function getQueueLength(): int;

    /**
     * Returns true if no promises are waiting to acquire the lock.
     */
    public function isQueueEmpty(): bool;
}
