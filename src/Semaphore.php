<?php

declare(strict_types=1);

namespace Hibla\Sync;

use function Hibla\async;

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
     * @var array<int, array{promise: Promise<$this>, needed: int}>
     */
    private array $queue = [];

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
    }

    /**
     * @return PromiseInterface<$this>
     */
    public function acquire(): PromiseInterface
    {
        return $this->acquireMany(1);
    }

    /**
     * @return PromiseInterface<$this>
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
        $id = spl_object_id($promise);
        $this->queue[$id] = ['promise' => $promise, 'needed' => $permits];

        $promise->onCancel(function () use ($id): void {
            unset($this->queue[$id]);
        });

        return $promise;
    }

    /**
     * @throws \LogicException If releasing more permits than capacity
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

        $this->available++;

        $id = array_key_first($this->queue);
        $entry = $this->queue[$id];

        if ($this->available >= $entry['needed']) {
            $this->available -= $entry['needed'];
            unset($this->queue[$id]);
            $entry['promise']->resolve($this);
        }
    }

    /**
     * @throws \InvalidArgumentException If permits is less than 1
     * @throws \LogicException If releasing more permits than capacity
     */
    public function releaseMany(int $permits): void
    {
        if ($permits < 1) {
            throw new \InvalidArgumentException('Must release at least 1 permit');
        }

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
     * Acquire a single permit, execute the callable, then release automatically.
     * Guarantees release even if the callable throws, rejects, or is cancelled.
     *
     * @template TReturn
     * @param callable(): TReturn $callable
     * @return PromiseInterface<TReturn>
     */
    public function withPermit(callable $callable): PromiseInterface
    {
        return $this->withPermits(1, $callable);
    }

    /**
     * Acquire N permits, execute the callable in a fiber, then release automatically.
     * The callable runs inside async() — use await() freely inside it.
     * Guarantees release on fulfillment, rejection, or cancellation.
     *
     * @template TReturn
     * @param callable(): TReturn $callable
     * @return PromiseInterface<TReturn>
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
     */
    public function tryAcquire(): bool
    {
        if ($this->available > 0) {
            $this->available--;

            return true;
        }

        return false;
    }

    public function getAvailable(): int
    {
        return $this->available;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getQueueLength(): int
    {
        return \count($this->queue);
    }

    public function isQueueEmpty(): bool
    {
        return \count($this->queue) === 0;
    }

    public function isFull(): bool
    {
        return $this->available === 0;
    }

    public function isIdle(): bool
    {
        return $this->available === $this->capacity;
    }
}
