<?php

declare(strict_types=1);

namespace Hibla\Sync\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * A counting semaphore for controlling concurrent access to shared resources.
 *
 * Allows up to N fibers to run a section simultaneously. Fibers that exceed
 * the permit count are queued in FIFO order and released as permits become
 * available. A semaphore with a capacity of 1 is equivalent to a mutex.
 *
 * Designed for use with hiblaphp/async. The withPermit() and withPermits()
 * helpers run their callable inside async() implicitly, so await() works
 * freely inside them and the critical section reads like ordinary synchronous PHP.
 */
interface SemaphoreInterface
{
    /**
     * Acquire a single permit.
     *
     * If a permit is available, it is immediately acquired and a resolved
     * promise containing this instance is returned.
     *
     * If no permits are available, a pending promise is created, added to
     * the FIFO queue, and returned. The promise resolves with this instance
     * when a permit becomes available.
     *
     * Cancelling the returned promise removes it from the queue immediately
     * without consuming any permit or skipping other waiters.
     *
     * @return PromiseInterface<$this>
     */
    public function acquire(): PromiseInterface;

    /**
     * Acquire N permits atomically.
     *
     * The promise only resolves when N permits are simultaneously available.
     * Permits accumulate across multiple release() calls — the promise will
     * not resolve early with fewer than the requested number.
     *
     * The head waiter in the queue is never bypassed by smaller requests
     * that arrive later — FIFO order is preserved to prevent starvation.
     *
     * Cancelling the returned promise removes it from the queue immediately.
     * No permits are consumed and no other waiters are affected.
     *
     * @return PromiseInterface<$this>
     * @throws \InvalidArgumentException If permits < 1 or permits > capacity.
     */
    public function acquireMany(int $permits): PromiseInterface;

    /**
     * Release a single permit back to the semaphore.
     *
     * If no fibers are waiting, the permit is returned to the available pool.
     * If fibers are waiting, the permit is offered to the head waiter and
     * resolves their promise if their full requirement is now met.
     *
     * @throws \LogicException If releasing would exceed semaphore capacity.
     */
    public function release(): void;

    /**
     * Release N permits back to the semaphore.
     *
     * Validates the full release before touching any state. If releasing N
     * permits would exceed the semaphore capacity, throws LogicException
     * before any permits are returned — no partial corruption.
     *
     * @throws \InvalidArgumentException If permits < 1.
     * @throws \LogicException If releasing would exceed semaphore capacity.
     */
    public function releaseMany(int $permits): void;

    /**
     * Acquire a single permit, execute the callable in a fiber, then release automatically.
     *
     * Release is guaranteed on all outcomes — fulfillment, rejection, and
     * cancellation — with no try/finally boilerplate required from the caller.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callable
     * @return PromiseInterface<TReturn>
     */
    public function withPermit(callable $callable): PromiseInterface;

    /**
     * Acquire N permits atomically, execute the callable in a fiber, then release automatically.
     *
     * Release of all N permits is guaranteed on all outcomes — fulfillment,
     * rejection, and cancellation — with no try/finally boilerplate required
     * from the caller.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callable
     * @return PromiseInterface<TReturn>
     * @throws \InvalidArgumentException If permits < 1 or permits > capacity.
     */
    public function withPermits(int $permits, callable $callable): PromiseInterface;

    /**
     * Try to acquire a single permit without waiting.
     *
     * Returns true if a permit was acquired, false if none are available.
     * Never queues.
     */
    public function tryAcquire(): bool;

    /**
     * Returns the number of permits currently available for immediate acquisition.
     */
    public function getAvailable(): int;

    /**
     * Returns the maximum number of permits this semaphore manages.
     */
    public function getCapacity(): int;

    /**
     * Returns the number of promises currently waiting to acquire permits.
     */
    public function getQueueLength(): int;

    /**
     * Returns true if no promises are waiting to acquire permits.
     */
    public function isQueueEmpty(): bool;

    /**
     * Returns true if no permits are available (all are currently held).
     */
    public function isFull(): bool;

    /**
     * Returns true if all permits are available (none are currently held).
     */
    public function isIdle(): bool;
}
