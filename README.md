# Hibla Sync

**Async-aware synchronization primitives for PHP built on the Hibla event loop.**

`hiblaphp/sync` provides a `Mutex` and `Semaphore` for coordinating access to shared state in async PHP applications. Both primitives are built on promises and fibers. They never block the thread, queue waiters cooperatively, and integrate cleanly with cancellation.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/sync.svg?style=flat-square)](https://github.com/hiblaphp/sync/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**
- [Installation](#installation)
- [Introduction](#introduction)
  - [Why you need this](#why-you-need-this)
  - [How this differs from Promise concurrency utilities](#how-this-differs-from-promise-concurrency-utilities)

**Mutex**
- [Basic Usage](#basic-usage)
- [`withLock()`](#withlock)
- [Queueing and fairness](#queueing-and-fairness)
- [Cancellation](#cancellation)

**Semaphore**
- [Basic Usage](#basic-usage-1)
- [`withPermit()` and `withPermits()`](#withpermit-and-withpermits)
- [`tryAcquire()`](#tryacquire)
- [`acquireMany()` and `releaseMany()`](#acquiremany-and-releasemany)
- [Queueing and fairness](#queueing-and-fairness-1)
- [Cancellation](#cancellation-1)

**Reference**
- [Interfaces](#interfaces)
- [Mutex API Reference](#mutex-api-reference)
- [Semaphore API Reference](#semaphore-api-reference)
- [Exception Reference](#exception-reference)

**Meta**
- [Development](#development)
- [License](#license)

---

## Installation
```bash
composer require hiblaphp/sync
```

**Requirements:**
- PHP 8.3+
- `hiblaphp/event-loop`
- `hiblaphp/promise`
- `hiblaphp/async`

---

## Introduction

`hiblaphp/sync` provides a `Mutex` and `Semaphore` for coordinating access to shared state in async PHP applications. Both primitives are built on promises and fibers. They never block the thread, queue waiters cooperatively, and integrate cleanly with cancellation.

> **Note:** This library is designed to be used with [`hiblaphp/async`](https://github.com/hiblaphp/async). The `withLock()` and `withPermit()` helpers run their callable inside `async()` implicitly, so `await()` works freely inside them and the critical section reads like ordinary synchronous PHP. While the lower-level `acquire()` and `release()` methods work with raw promise chains, `withLock()` and `withPermit()` are the recommended API for any `hiblaphp/async` application.

### Why you need this

PHP is single-threaded. Only one piece of code runs at any given moment. This leads to an easy assumption: if there are no threads, there are no race conditions. This assumption is wrong in async PHP.

The source of races in async PHP is not parallelism. It is **cooperative context switching**. Every time a fiber calls `await()`, it suspends and yields control back to the event loop. The event loop then resumes another fiber. When that fiber also suspends, the first fiber may resume again, and by then, shared state may have changed underneath it.

Consider a counter incremented by 5 concurrent fibers:
```php
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;
use Hibla\Promise\Promise;

$counter = 0;

$tasks = [];
for ($i = 0; $i < 5; $i++) {
    $tasks[] = async(function () use (&$counter) {
        $old = $counter;       // fiber reads: 0
        await(delay(0.01));    // fiber suspends — other fibers run here
        $counter = $old + 1;   // fiber writes: 0 + 1 = 1
                               // but another fiber also read 0 and wrote 1
                               // the intermediate increments are lost
    });
}

await(Promise::all($tasks));
echo $counter; // expected: 5 — actual: could be 1, 2, 3, or 4
```

Every `await()` is a potential context switch. Any shared state read before an `await()` may be stale by the time the fiber resumes. This is the same class of bug as a thread race condition, just triggered by `await()` instead of a CPU preemption.

The same problem appears in any real-world scenario involving shared state and async I/O:
```php
use function Hibla\async;
use function Hibla\await;

// Race — cache check and write are not atomic
async(function () use ($cache, $key) {
    if (!await($cache->has($key))) {        // fiber suspends here
        // another fiber passed this check while this one was suspended
        $value = await(computeExpensive()); // both fibers compute
        await($cache->set($key, $value));   // both fibers write — duplicate work
    }
});

// Race — balance check and deduction are not atomic
async(function () use ($account, $amount) {
    $balance = await($account->getBalance()); // fiber suspends here
    // another fiber also read the balance and is about to deduct
    if ($balance >= $amount) {
        await($account->deduct($amount)); // both deductions proceed — overdraft
    }
});
```

A `Mutex` closes these windows. The entire read-check-write sequence runs inside `withLock()`. No other fiber can enter until the current one exits, regardless of how many `await()` calls happen inside:
```php
use Hibla\Sync\Mutex;
use function Hibla\async;
use function Hibla\await;

$mutex = new Mutex();

async(function () use ($mutex, $cache, $key) {
    await($mutex->withLock(function () use ($cache, $key) {
        if (!await($cache->has($key))) {
            $value = await(computeExpensive());
            await($cache->set($key, $value));
        }
    }));
});
```

The key insight is that **atomicity in async PHP means uninterrupted from the perspective of other fibers**, not uninterrupted from the perspective of the CPU. The mutex does not stop the event loop from running. While one fiber holds the lock and is suspended inside `await()`, other fibers that do not compete for this lock run freely. Only fibers that call `acquire()` on the same mutex are made to wait.

### How this differs from Promise concurrency utilities

`hiblaphp/promise` ships utilities like `Promise::concurrent()`, `Promise::batch()`, and `Promise::map()` that control how many tasks run simultaneously. These answer the question: **how many tasks should run at the same time?**

`hiblaphp/sync` answers a different question: **how do concurrent tasks safely share state?**

The distinction matters. Consider fetching 100 records from an API:
```php
use function Hibla\await;
use Hibla\Promise\Promise;

// Promise::concurrent() — controls throughput
// Each task is independent — no shared mutable state involved
await(Promise::concurrent(
    array_map(fn($id) => fn() => fetchRecord($id), $ids),
    concurrency: 10
));
```

Now consider 10 concurrent workers that all update a shared counter and log:
```php
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;
use Hibla\Promise\Promise;

// Promise::concurrent() does NOT protect shared state
// All 10 workers run concurrently and race on $counter and $log
await(Promise::concurrent(
    array_map(fn($i) => fn() => async(function () use (&$counter, &$log, $i) {
        $old = $counter;
        await(delay(0.01)); // context switch — other workers increment here
        $counter = $old + 1; // stale write — race condition
        $log[] = "Worker $i: $old -> {$counter}";
    }), range(1, 10)),
    concurrency: 10
));
```

`Promise::concurrent()` does not know or care about `$counter`. It only controls when tasks start. A `Mutex` is what makes the increment safe:
```php
use Hibla\Sync\Mutex;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;
use Hibla\Promise\Promise;

$mutex = new Mutex();

await(Promise::concurrent(
    array_map(fn($i) => fn() => $mutex->withLock(function () use (&$counter, &$log, $i) {
        $old = $counter;
        await(delay(0.01)); // safe — no other fiber can enter this block
        $counter = $old + 1;
        $log[] = "Worker $i: $old -> {$counter}";
    }), range(1, 10)),
    concurrency: 10
));
// $counter is always 10 — no race
```

The two compose naturally. `Promise::concurrent()` controls how many tasks start, while `Mutex` and `Semaphore` control what those tasks can safely do once running. They solve different problems and are commonly used together.

A `Semaphore` can look similar to `Promise::concurrent()` at a glance, since both limit how many fibers do something at once. The difference is scope. `Promise::concurrent()` limits task throughput at one call site. A `Semaphore` limits access to a specific shared resource from anywhere in the codebase, across multiple independent call sites:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;
use Hibla\Promise\Promise;

// Promise::concurrent() — throughput control at one call site
// Tasks started elsewhere are not affected
await(Promise::concurrent($tasks, concurrency: 3));

// Semaphore — resource access control across all call sites
// Every acquire() anywhere competes for the same pool of permits
$dbPool = new Semaphore(3); // max 3 concurrent DB connections, enforced globally

// call site A
async(function () use ($dbPool, $queryA) {
    await($dbPool->withPermit(function () use ($queryA) {
        return await(queryDatabase($queryA));
    }));
});

// call site B — independently competes for the same semaphore
async(function () use ($dbPool, $queryB) {
    await($dbPool->withPermit(function () use ($queryB) {
        return await(queryDatabase($queryB));
    }));
});
```

| | `Promise::concurrent()` / `Promise::batch()` | `Mutex` / `Semaphore` |
|---|---|---|
| **Question answered** | How many tasks run at once? | How do running tasks share state safely? |
| **Unit of control** | Task lifecycle (start / stop) | Access to a shared resource |
| **Typical use case** | API rate limiting, batch processing, queue workers | Shared counters, caches, connection pools, critical sections |
| **Interaction model** | Tasks are independent | Tasks coordinate, one waits for another to finish |
| **What it prevents** | Overwhelming external systems | Race conditions on shared mutable state |
| **Scope** | Single call site | Across any number of call sites |

Use `Promise::concurrent()` when the concern is throughput and scheduling. Use `Mutex` and `Semaphore` when the concern is correctness and shared state. In a real application you will typically use both.

---

## Mutex

A `Mutex` (mutual exclusion lock) ensures that only one fiber runs a critical section at a time. All other fibers that attempt to enter queue and wait their turn in FIFO order. The event loop continues running freely while waiters are queued. Only fibers competing for this specific mutex are held back.

### Basic Usage

`acquire()` returns a promise that resolves with the mutex instance when the lock is available. Call `release()` on the resolved instance to unlock:
```php
use Hibla\Sync\Mutex;
use function Hibla\async;
use function Hibla\await;

$mutex = new Mutex();

async(function () use ($mutex) {
    $lock = await($mutex->acquire());

    try {
        // critical section — only one fiber runs here at a time
        $user = await(fetchUser(1));
        await(saveUser($user));
    } finally {
        $lock->release(); // always release — even if the section throws
    }
});
```

Always release in a `finally` block. A missing `release()` after a throw leaves the mutex permanently locked and all waiters stuck forever. For this reason, `withLock()` is the preferred API.

### `withLock()`

`withLock()` acquires the lock, runs the callable inside a fiber, and releases automatically on fulfillment, rejection, and cancellation. The callable runs inside `async()` implicitly, so `await()` can be used freely inside it without any extra wrapping:
```php
use Hibla\Sync\Mutex;
use function Hibla\async;
use function Hibla\await;

$mutex = new Mutex();

async(function () use ($mutex) {
    $result = await($mutex->withLock(function () {
        $user   = await(fetchUser(1));
        $orders = await(fetchOrders($user->id));
        return processOrders($user, $orders);
    }));
});
```

The callable looks like synchronous code. Each `await()` suspends only the current fiber. The event loop continues running other work, but the mutex remains locked for the entire duration, including across all awaited operations.

**Release is guaranteed in all outcomes:**
```php
use Hibla\Sync\Mutex;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

$mutex = new Mutex();

// Fulfillment — released after the callable returns
async(function () use ($mutex) {
    await($mutex->withLock(function () {
        await(doWork());
        return 'done';
    }));
});

// Rejection — released when the callable throws or an awaited promise rejects
async(function () use ($mutex) {
    try {
        await($mutex->withLock(function () {
            await(doWork());
            throw new \RuntimeException('Something went wrong');
        }));
    } catch (\RuntimeException $e) {
        // lock is already released here
    }
});

// Cancellation — released immediately when the outer promise is cancelled
async(function () use ($mutex) {
    $promise = $mutex->withLock(function () {
        await(delay(10.0));
    });

    $promise->catch(static fn() => null);

    await(delay(0.1));
    $promise->cancel(); // lock released immediately — waiters can proceed
});
```

### Queueing and fairness

When the mutex is locked, subsequent `acquire()` and `withLock()` calls queue in FIFO order. `release()` passes ownership directly to the next waiter without unlocking. The mutex stays locked the whole time ownership transfers:
```php
use Hibla\Sync\Mutex;
use function Hibla\async;
use function Hibla\await;

$mutex = new Mutex();

async(function () use ($mutex) {
    $lock = await($mutex->acquire());
    echo $mutex->getQueueLength(); // 0

    $waiter1 = $mutex->acquire();
    $waiter2 = $mutex->acquire();
    echo $mutex->getQueueLength(); // 2

    $lock->release();
    echo $mutex->isLocked();       // true — waiter1 now holds it
    echo $mutex->getQueueLength(); // 1

    $lock1 = await($waiter1);
    $lock1->release();             // waiter2 gets the lock

    $lock2 = await($waiter2);
    $lock2->release();             // fully unlocked
    echo $mutex->isLocked();       // false
});
```

### Cancellation

Cancelling a queued `acquire()` or `withLock()` promise removes it from the queue immediately and cleanly. The lock state is unaffected and the next live waiter is not skipped:
```php
use Hibla\Sync\Mutex;
use function Hibla\async;
use function Hibla\await;

$mutex = new Mutex();

async(function () use ($mutex) {
    $lock = await($mutex->acquire());

    $waiterA = $mutex->acquire();
    $waiterB = $mutex->acquire();
    echo $mutex->getQueueLength(); // 2

    $waiterA->catch(static fn() => null);
    $waiterA->cancel();
    echo $mutex->getQueueLength(); // 1 — waiterA removed, waiterB still queued

    $lock->release();
    $lockB = await($waiterB);      // waiterB gets the lock — not skipped
    $lockB->release();
});
```

---

## Semaphore

A `Semaphore` allows up to N fibers to run a section simultaneously. It generalises the `Mutex`. A `Mutex` is a `Semaphore` with a capacity of 1. Common uses are connection pools, rate limiting, and bulk resource acquisition.

### Basic Usage

Construct with a permit count. `acquire()` returns a promise that resolves with the semaphore instance when a permit is available:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;

// Allow up to 3 concurrent database connections
$semaphore = new Semaphore(3);

async(function () use ($semaphore) {
    $permit = await($semaphore->acquire());

    try {
        $result = await(queryDatabase($query));
    } finally {
        $permit->release();
    }
});
```

### `withPermit()` and `withPermits()`

`withPermit()` acquires one permit and runs the callable in a fiber. `withPermits()` acquires N permits atomically. Both release automatically on fulfillment, rejection, and cancellation. The callable runs inside `async()` implicitly, so `await()` works freely inside it:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;

$semaphore = new Semaphore(3);

// Acquire 1 permit — releases automatically when done
async(function () use ($semaphore) {
    $result = await($semaphore->withPermit(function () {
        $data = await(fetchFromApi());
        return processData($data);
    }));
});

// Acquire 3 permits atomically — only proceeds when all 3 are available
async(function () use ($semaphore) {
    $result = await($semaphore->withPermits(3, function () {
        $a = await(fetchA());
        $b = await(fetchB());
        $c = await(fetchC());
        return [$a, $b, $c];
    }));
});
```

Release is guaranteed on all outcomes including cancellation:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

$semaphore = new Semaphore(1);

async(function () use ($semaphore) {
    $promise = $semaphore->withPermit(function () {
        await(delay(10.0));
    });

    $promise->catch(static fn() => null);

    await(delay(0.1));
    $promise->cancel(); // permit released immediately — next waiter can proceed
});
```

### `tryAcquire()`

`tryAcquire()` attempts to acquire one permit without waiting. Returns `true` if acquired, `false` if no permits are available. Never queues:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;

$semaphore = new Semaphore(3);

async(function () use ($semaphore) {
    if ($semaphore->tryAcquire()) {
        try {
            $result = await(doWork());
        } finally {
            $semaphore->release();
        }
    } else {
        // no permits available right now — skip or use a fallback
    }
});
```

### `acquireMany()` and `releaseMany()`

`acquireMany(N)` acquires N permits atomically. The promise only resolves when N permits are simultaneously available. It accumulates permits across multiple `release()` calls and will not resolve early with fewer than requested:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;

$semaphore = new Semaphore(5);

async(function () use ($semaphore) {
    // Waits until 4 permits are free at the same time
    $permit = await($semaphore->acquireMany(4));

    try {
        await(doBulkWork());
    } finally {
        $semaphore->releaseMany(4);
    }
});
```

`releaseMany()` validates the full release before touching any state. If releasing N permits would exceed capacity, it throws `LogicException` before any permits are returned, so there is no partial corruption:
```php
use Hibla\Sync\Semaphore;

$semaphore = new Semaphore(3);

try {
    $semaphore->releaseMany(5); // throws immediately — nothing released
} catch (\LogicException $e) {
    // semaphore state is unchanged
}
```

### Queueing and fairness

Waiters are queued in FIFO order. The head waiter accumulates permits across multiple `release()` calls until its full requirement is met. Smaller requests that arrive later do not jump the queue. This prevents starvation of large permit requests:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;

$semaphore = new Semaphore(4);

async(function () use ($semaphore) {
    await($semaphore->acquireMany(4)); // holds all 4

    $waiter = $semaphore->acquireMany(3); // queued — needs 3

    $semaphore->release(); // available: 1 — waiter still waiting
    $semaphore->release(); // available: 2 — waiter still waiting
    $semaphore->release(); // available: 3 — waiter resolves, available: 0

    await($waiter);
    $semaphore->releaseMany(3);

    $semaphore->release(); // releases the remaining held permit
});
```

### Cancellation

Cancelling a queued `acquire()`, `acquireMany()`, `withPermit()`, or `withPermits()` promise removes it from the queue immediately. No permit is consumed and the next waiter is not affected:
```php
use Hibla\Sync\Semaphore;
use function Hibla\async;
use function Hibla\await;

$semaphore = new Semaphore(1);

async(function () use ($semaphore) {
    await($semaphore->acquire()); // holds the only permit

    $waiterA = $semaphore->acquire();
    $waiterB = $semaphore->acquire();
    echo $semaphore->getQueueLength(); // 2

    $waiterA->catch(static fn() => null);
    $waiterA->cancel();
    echo $semaphore->getQueueLength(); // 1 — waiterA removed cleanly

    $semaphore->release();
    $permitB = await($waiterB); // waiterB gets the permit — not skipped
    $permitB->release();
});
```

---

## Interfaces

Both primitives are backed by interfaces, making them replaceable for testing and extension. Type-hint against the interface rather than the concrete class anywhere you need to inject either primitive:
```php
use Hibla\Sync\Interfaces\MutexInterface;
use Hibla\Sync\Interfaces\SemaphoreInterface;

class UserRepository
{
    public function __construct(
        private readonly MutexInterface $mutex,
    ) {}

    public function save(User $user): PromiseInterface
    {
        return $this->mutex->withLock(function () use ($user) {
            return await($this->db->save($user));
        });
    }
}
```

| Interface | Concrete class | Description |
|---|---|---|
| `Hibla\Sync\Interfaces\MutexInterface` | `Hibla\Sync\Mutex` | Mutual exclusion lock |
| `Hibla\Sync\Interfaces\SemaphoreInterface` | `Hibla\Sync\Semaphore` | Counting semaphore |

---

## Mutex API Reference

| Method | Description |
|---|---|
| `acquire(): PromiseInterface<$this>` | Acquire the lock. Resolves immediately if unlocked, queues otherwise. |
| `release(): void` | Release the lock. Passes ownership to the next waiter if any. Throws `LogicException` if not locked. |
| `withLock(callable $fn): PromiseInterface` | Acquire the lock, run the callable in a fiber, release automatically on any outcome. |
| `isLocked(): bool` | Returns `true` if the lock is currently held. |
| `getQueueLength(): int` | Number of waiters currently queued. |
| `isQueueEmpty(): bool` | Returns `true` if no waiters are queued. |

---

## Semaphore API Reference

| Method | Description |
|---|---|
| `acquire(): PromiseInterface<$this>` | Acquire one permit. Resolves immediately if available, queues otherwise. |
| `acquireMany(int $permits): PromiseInterface<$this>` | Acquire N permits atomically. Only resolves when N are simultaneously available. |
| `release(): void` | Release one permit. Passes to the next waiter if any. Throws `LogicException` if over-releasing. |
| `releaseMany(int $permits): void` | Release N permits. Validates the full release before touching state. |
| `withPermit(callable $fn): PromiseInterface` | Acquire 1 permit, run the callable in a fiber, release automatically on any outcome. |
| `withPermits(int $permits, callable $fn): PromiseInterface` | Acquire N permits, run the callable in a fiber, release automatically on any outcome. |
| `tryAcquire(): bool` | Try to acquire 1 permit without waiting. Returns `false` if unavailable. Never queues. |
| `getAvailable(): int` | Number of permits currently available. |
| `getCapacity(): int` | Maximum number of permits. |
| `getQueueLength(): int` | Number of waiters currently queued. |
| `isQueueEmpty(): bool` | Returns `true` if no waiters are queued. |
| `isFull(): bool` | Returns `true` if no permits are available. |
| `isIdle(): bool` | Returns `true` if all permits are available (none in use). |

---

## Exception Reference

| Exception | When it is thrown |
|---|---|
| `\LogicException` | `Mutex::release()` called when not locked. `Semaphore::release()` or `releaseMany()` would exceed capacity. |
| `\InvalidArgumentException` | `Semaphore` constructed with permits < 1. `acquireMany()` or `releaseMany()` called with an invalid permit count. |

---

## Development
```bash
git clone https://github.com/hiblaphp/sync.git
cd sync
composer install
./vendor/bin/pest
./vendor/bin/phpstan analyse
```

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.