<?php

declare(strict_types=1);

namespace Hibla\Sync;

use function Hibla\async;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * A counting semaphore for controlling concurrent access to shared resources.
 *
 * Allows up to N fibers to run a section simultaneously. Fibers that exceed
 * the permit count are queued in FIFO order and released as permits become
 * available. A Semaphore with a capacity of 1 is equivalent to a Mutex.
 *
 * Common use cases:
 * - Connection pools: limit concurrent database or HTTP connections
 * - Rate limiting: cap concurrent outbound API calls
 * - Bulk acquisition: atomically reserve N slots for work requiring multiple resources
 *
 * The event loop continues running freely while fibers are waiting — only
 * fibers competing for this specific semaphore are held back. A semaphore
 * only limits access when multiple fibers share the same instance. Each
 * instance manages an independent pool of permits.
 *
 * Designed for use with hiblaphp/async. The withPermit() and withPermits()
 * helpers run their callable inside async() implicitly, so await() works
 * freely inside them and the critical section reads like ordinary synchronous PHP.
 *
 * Basic usage:
 * ```php
 * $semaphore = new Semaphore(3); // max 3 concurrent DB connections
 *
 * await($semaphore->withPermit(function () {
 *     $result = await(queryDatabase($query));
 *     return $result;
 * }));
 * ```
 *
 * Manual usage when fine-grained control is needed:
 * ```php
 * $permit = await($semaphore->acquire());
 * try {
 *     await(doWork());
 * } finally {
 *     $permit->release();
 * }
 * ```
 */
class Semaphore
{
    private int $available;

    private int $capacity;

    /**
     * Promises queued waiting to acquire permits, keyed by spl_object_id.
     *
     * Keyed array preserves FIFO insertion order and allows O(1) removal
     * by object ID when a queued promise is cancelled. Each entry tracks
     * the pending Promise and the number of permits it needs.
     *
     * The head waiter accumulates permits across multiple release() calls
     * until its full requirement is met — smaller requests behind it do
     * not jump the queue, preventing starvation of large-permit requests.
     *
     * @var array<int, array{promise: Promise<$this>, needed: int}>
     */
    private array $queue = [];

    /**
     * Create a new Semaphore instance.
     *
     * @param  int  $permits  The maximum number of concurrent permits (must be >= 1).
     *                        Use 1 for mutual exclusion (equivalent to a Mutex).
     *                        Use N > 1 for connection pools, rate limiting, etc.
     * @throws \InvalidArgumentException If permits is less than 1.
     */
    public function __construct(int $permits = 1)
    {
        if ($permits < 1) {
            throw new \InvalidArgumentException('Semaphore permits must be at least 1');
        }

        $this->capacity = $permits;
        $this->available = $permits;
    }

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
     * @return PromiseInterface<$this> Promise that resolves with this semaphore
     *                                 instance when a permit is acquired.
     */
    public function acquire(): PromiseInterface
    {
        return $this->acquireMany(1);
    }

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
     * @param  int  $permits  Number of permits to acquire atomically (must be >= 1
     *                        and <= capacity).
     * @return PromiseInterface<$this> Promise that resolves with this semaphore
     *                                 instance when all N permits are acquired.
     * @throws \InvalidArgumentException If permits < 1 or permits > capacity.
     */
    public function acquireMany(int $permits): PromiseInterface
    {
        if ($permits < 1) {
            throw new \InvalidArgumentException('Must acquire at least 1 permit');
        }

        if ($permits > $this->capacity) {
            throw new \InvalidArgumentException(
                "Cannot acquire {$permits} permits (capacity is {$this->capacity})"
            );
        }

        if ($this->available >= $permits) {
            $this->available -= $permits;

            return Promise::resolved($this);
        }

        /** @var Promise<$this> $promise */
        $promise = new Promise();
        $id = spl_object_id($promise);
        $this->queue[$id] = ['promise' => $promise, 'needed' => $permits];

        $promise->onCancel(function () use ($id): void {
            unset($this->queue[$id]);
            // No permit was granted — nothing to return to the pool.
        });

        return $promise;
    }

    /**
     * Release a single permit back to the semaphore.
     *
     * If no fibers are waiting, the permit is returned to the available pool.
     *
     * If fibers are waiting, the permit is offered to the head waiter. If
     * the head waiter's accumulated permits now meet its requirement, its
     * promise is resolved and it is removed from the queue. Otherwise the
     * permit stays in the pool and the head waiter continues accumulating.
     *
     * Only fibers that hold a permit should call release(). Releasing more
     * permits than the semaphore capacity throws immediately.
     *
     * @throws \LogicException If releasing would exceed semaphore capacity.
     */
    public function release(): void
    {
        if (\count($this->queue) === 0) {
            if ($this->available >= $this->capacity) {
                throw new \LogicException('Cannot release more permits than semaphore capacity');
            }

            $this->available++;

            return;
        }

        // Return the permit to the pool first so the head waiter
        // can accumulate permits across multiple release() calls
        $this->available++;

        $id    = array_key_first($this->queue);
        $entry = $this->queue[$id];

        // Only satisfy the head waiter when enough permits have accumulated
        if ($this->available >= $entry['needed']) {
            $this->available -= $entry['needed'];
            unset($this->queue[$id]);
            $entry['promise']->resolve($this);
        }
    }

    /**
     * Release N permits back to the semaphore.
     *
     * Validates the full release before touching any state. If releasing N
     * permits would exceed the semaphore capacity, throws LogicException
     * before any permits are returned — no partial corruption.
     *
     * Internally calls release() N times, satisfying queued waiters as
     * permits become available.
     *
     * @param  int  $permits  Number of permits to release (must be >= 1).
     * @throws \InvalidArgumentException If permits < 1.
     * @throws \LogicException If releasing would exceed semaphore capacity.
     */
    public function releaseMany(int $permits): void
    {
        if ($permits < 1) {
            throw new \InvalidArgumentException('Must release at least 1 permit');
        }

        // Calculate how many permits would return to available after
        // satisfying queued waiters — validate before touching any state
        $returning = $permits;
        foreach ($this->queue as $entry) {
            $returning -= $entry['needed'];
            if ($returning <= 0) {
                break;
            }
        }

        if ($this->available + max(0, $returning) > $this->capacity) {
            throw new \LogicException('Cannot release more permits than semaphore capacity');
        }

        for ($i = 0; $i < $permits; $i++) {
            $this->release();
        }
    }

    /**
     * Acquire a single permit, execute the callable in a fiber, then release automatically.
     *
     * Shorthand for withPermits(1, $callable). This is the preferred API for
     * single-permit acquisition — release is guaranteed on all outcomes with
     * no try/finally boilerplate required from the caller.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callable  The callable to execute inside the permit.
     *                                          Use await() freely — it runs in a fiber.
     * @return PromiseInterface<TReturn> Promise that resolves with the callable's
     *                                   return value once the permit is released.
     */
    public function withPermit(callable $callable): PromiseInterface
    {
        return $this->withPermits(1, $callable);
    }

    /**
     * Acquire N permits atomically, execute the callable in a fiber, then release automatically.
     *
     * This is the preferred API over acquireMany() and releaseMany(). Release
     * of all N permits is guaranteed on all outcomes — fulfillment, rejection,
     * and cancellation — with no try/finally boilerplate required from the caller.
     *
     * The callable runs inside async() implicitly. Use await() freely inside
     * it — all N permits remain held across every suspension point until the
     * callable completes or throws:
     *
     * ```php
     * await($semaphore->withPermits(3, function () {
     *     $a = await(fetchA()); // all 3 permits held across this await
     *     $b = await(fetchB()); // and this one
     *     $c = await(fetchC()); // and this one
     *     return [$a, $b, $c];
     * }));
     * ```
     *
     * Cancelling the promise returned by withPermits() releases all N permits
     * immediately and cancels the in-flight callable.
     *
     * @template TReturn
     * @param  int  $permits  Number of permits to acquire atomically (must be >= 1
     *                        and <= capacity).
     * @param  callable(): TReturn  $callable  The callable to execute inside the permits.
     *                                          Use await() freely — it runs in a fiber.
     * @return PromiseInterface<TReturn> Promise that resolves with the callable's
     *                                   return value once all permits are released.
     * @throws \InvalidArgumentException If permits < 1 or permits > capacity.
     */
    public function withPermits(int $permits, callable $callable): PromiseInterface
    {
        return $this->acquireMany($permits)->then(function () use ($permits, $callable) {
            $inner = async($callable);

            $inner->then(
                onFulfilled: function () use ($permits): void {
                    $this->releaseMany($permits);
                },
                onRejected: function () use ($permits): void {
                    $this->releaseMany($permits);
                }
            );

            $inner->onCancel(function () use ($permits): void {
                $this->releaseMany($permits);
            });

            return $inner;
        });
    }

    /**
     * Try to acquire a single permit without waiting.
     *
     * Returns true immediately if a permit is available and acquires it.
     * Returns false immediately if no permits are available. Never queues.
     *
     * Use this when you want to do something else if the resource is busy
     * rather than waiting. If you acquire a permit, release it manually
     * in a finally block when done.
     *
     * @return bool True if a permit was acquired, false if none are available.
     */
    public function tryAcquire(): bool
    {
        if ($this->available > 0) {
            $this->available--;

            return true;
        }

        return false;
    }

    /**
     * Returns the number of permits currently available for immediate acquisition.
     */
    public function getAvailable(): int
    {
        return $this->available;
    }

    /**
     * Returns the maximum number of permits this semaphore manages.
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Returns the number of promises currently waiting to acquire permits.
     */
    public function getQueueLength(): int
    {
        return \count($this->queue);
    }

    /**
     * Returns true if no promises are waiting to acquire permits.
     */
    public function isQueueEmpty(): bool
    {
        return \count($this->queue) === 0;
    }

    /**
     * Returns true if no permits are available (all are currently held).
     */
    public function isFull(): bool
    {
        return $this->available === 0;
    }

    /**
     * Returns true if all permits are available (none are currently held).
     */
    public function isIdle(): bool
    {
        return $this->available === $this->capacity;
    }
}