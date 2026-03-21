<?php

declare(strict_types=1);

namespace Hibla\Sync;

use function Hibla\async;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sync\Interfaces\SemaphoreInterface;

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
class Semaphore implements SemaphoreInterface
{
    private int $available;

    private int $capacity;

    /**
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
     * @inheritDoc
     */
    public function acquire(): PromiseInterface
    {
        return $this->acquireMany(1);
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
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

        $id = array_key_first($this->queue);
        $entry = $this->queue[$id];

        // Only satisfy the head waiter when enough permits have accumulated
        if ($this->available >= $entry['needed']) {
            $this->available -= $entry['needed'];
            unset($this->queue[$id]);
            $entry['promise']->resolve($this);
        }
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function withPermit(callable $callable): PromiseInterface
    {
        return $this->withPermits(1, $callable);
    }

    /**
     * @inheritDoc
     *
     * @template TReturn
     * @param int $permits Number of permits to acquire atomically (must be >= 1 and <= capacity).
     * @param  callable(): TReturn $callable  The callable to execute inside the permits.
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
     * @inheritDoc
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
     * @inheritDoc
     */
    public function getAvailable(): int
    {
        return $this->available;
    }

    /**
     * @inheritDoc
     */
    public function getCapacity(): int
    {
        return $this->capacity;
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

    /**
     * @inheritDoc
     */
    public function isFull(): bool
    {
        return $this->available === 0;
    }

    /**
     * @inheritDoc
     */
    public function isIdle(): bool
    {
        return $this->available === $this->capacity;
    }
}
