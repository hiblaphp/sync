<?php

declare(strict_types=1);

namespace Hibla\Sync;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class Semaphore
{
    /**
     * The current number of available permits.
     */
    private int $available;

    /**
     * The maximum number of permits (capacity).
     */
    private int $capacity;

    /**
     * Queue of promises waiting to acquire a permit.
     *
     * When all permits are in use, subsequent acquire() calls create promises
     * that are queued here. When a permit is released, the next promise
     * in the queue is resolved.
     *
     * @var \SplQueue<Promise<$this>>
     */
    private \SplQueue $queue;

    /**
     * Create a new Semaphore instance.
     *
     * @param int $permits The maximum number of concurrent permits (must be >= 1)
     * @throws \InvalidArgumentException If permits is less than 1
     */
    public function __construct(int $permits = 1)
    {
        if ($permits < 1) {
            throw new \InvalidArgumentException('Semaphore permits must be at least 1');
        }

        $this->capacity = $permits;
        $this->available = $permits;
        $this->queue = new \SplQueue();
    }

    /**
     * Acquire a permit from the semaphore.
     *
     * If permits are available, one is immediately acquired and a resolved
     * promise containing the semaphore instance is returned.
     *
     * If no permits are available, a pending promise is created and added
     * to the queue. The promise will be resolved when a permit becomes available.
     *
     * @return PromiseInterface<$this> A promise that resolves with this semaphore instance
     */
    public function acquire(): PromiseInterface
    {
        if ($this->available > 0) {
            $this->available--;

            return Promise::resolved($this);
        }

        /** @var Promise<$this> $promise */
        $promise = new Promise();
        $this->queue->enqueue($promise);

        return $promise;
    }

    /**
     * Release a permit back to the semaphore.
     *
     * This method releases a permit and processes the next waiting
     * promise in the queue (if any). If no promises are waiting, the
     * available permit count is incremented.
     *
     * Important: This method should only be called by tasks that have
     * successfully acquired a permit. Calling release() without holding
     * a permit may lead to undefined behavior.
     *
     * @throws \RuntimeException If the queue contains an invalid promise type
     * @throws \LogicException If attempting to release more permits than capacity
     */
    public function release(): void
    {
        if ($this->queue->isEmpty()) {
            if ($this->available >= $this->capacity) {
                throw new \LogicException('Cannot release more permits than semaphore capacity');
            }

            $this->available++;
        } else {
            $promise = $this->queue->dequeue();

            if (! $promise instanceof Promise) {
                throw new \RuntimeException('Invalid promise type in semaphore queue');
            }

            $promise->resolve($this);
        }
    }

    /**
     * Get the number of currently available permits.
     *
     * @return int The number of permits available for immediate acquisition
     */
    public function getAvailable(): int
    {
        return $this->available;
    }

    /**
     * Get the maximum capacity of the semaphore.
     *
     * @return int The total number of permits this semaphore can manage
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Get the number of promises waiting to acquire a permit.
     *
     * @return int The number of promises in the waiting queue
     */
    public function getQueueLength(): int
    {
        return $this->queue->count();
    }

    /**
     * Check if there are any promises waiting in the queue.
     *
     * @return bool True if the queue is empty, false if there are waiting promises
     */
    public function isQueueEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    /**
     * Check if all permits are currently in use.
     *
     * @return bool True if no permits are available, false otherwise
     */
    public function isFull(): bool
    {
        return $this->available === 0;
    }

    /**
     * Check if all permits are available (none in use).
     *
     * @return bool True if all permits are available, false otherwise
     */
    public function isEmpty(): bool
    {
        return $this->available === $this->capacity;
    }

    /**
     * Try to acquire a permit without waiting.
     *
     * This is a non-blocking variant of acquire(). If a permit is available,
     * it is acquired and true is returned. If no permits are available,
     * false is returned immediately without queuing.
     *
     * @return bool True if a permit was acquired, false otherwise
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
     * Acquire multiple permits at once.
     *
     * This method attempts to acquire the specified number of permits.
     * If not enough permits are available, a promise is queued that will
     * resolve when the requested number of permits becomes available.
     *
     * @param int $permits Number of permits to acquire (must be >= 1 and <= capacity)
     * @return PromiseInterface<$this> A promise that resolves with this semaphore instance
     * @throws \InvalidArgumentException If permits is invalid
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

        for ($i = 0; $i < $permits; $i++) {
            $this->queue->enqueue($promise);
        }

        return $promise;
    }

    /**
     * Release multiple permits at once.
     *
     * @param int $permits Number of permits to release (must be >= 1)
     * @throws \InvalidArgumentException If permits is less than 1
     */
    public function releaseMany(int $permits): void
    {
        if ($permits < 1) {
            throw new \InvalidArgumentException('Must release at least 1 permit');
        }

        for ($i = 0; $i < $permits; $i++) {
            $this->release();
        }
    }
}
