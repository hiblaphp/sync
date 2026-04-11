<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\asyncFn;
use function Hibla\await;
use function Hibla\delay;

use Hibla\Promise\Promise;
use Hibla\Sync\Mutex;

describe('Basic Mutex Functionality', function () {
    it('starts in correct initial state and handles acquire/release', function () {
        $mutex = new Mutex();

        expect($mutex->isLocked())->toBeFalse();
        expect($mutex->queueLength)->toBe(0);
        expect($mutex->isQueueEmpty())->toBeTrue();

        $lockPromise = $mutex->acquire();
        expect($mutex->isLocked())->toBeTrue();

        $acquiredMutex = await($lockPromise);
        expect($acquiredMutex)->toBe($mutex);

        $acquiredMutex->release();
        expect($mutex->isLocked())->toBeFalse();
    });
});

describe('Concurrent Access Protection', function () {
    it('protects shared resources from race conditions', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];
        $expectedResults = [];

        for ($i = 1; $i <= 5; $i++) {
            $expectedResults[] = "Task-$i completed";
            $tasks[] = async(function () use ($mutex, &$sharedCounter, &$sharedLog, $i) {
                $lock = await($mutex->acquire());

                $oldValue = $sharedCounter;
                await(delay(0.01));
                $sharedCounter++;
                $sharedLog[] = "Task-$i: $oldValue -> {$sharedCounter}";

                $lock->release();

                return "Task-$i completed";
            });
        }

        $results = [];
        foreach ($tasks as $task) {
            $results[] = await($task);
        }

        expect($sharedCounter)->toBe(5);
        expect($sharedLog)->toHaveCount(5);
        expect($results)->toBe($expectedResults);

        for ($i = 0; $i < 5; $i++) {
            expect($sharedLog[$i])->toContain("$i -> " . ($i + 1));
        }
    });
});

describe('Promise::all() Integration', function () {
    it('integrates correctly with Promise::all()', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];

        for ($i = 1; $i <= 4; $i++) {
            $tasks[] = async(function () use ($mutex, &$sharedCounter, &$sharedLog, $i) {
                $lock = await($mutex->acquire());

                $oldValue = $sharedCounter;
                await(delay(0.02));
                $sharedCounter++;
                $sharedLog[] = "AllTask-$i: $oldValue -> {$sharedCounter}";

                $lock->release();

                return "AllTask-$i result: {$sharedCounter}";
            });
        }

        $results = await(Promise::all($tasks));

        expect($sharedCounter)->toBe(4);
        expect($results)->toHaveCount(4);
        expect($sharedLog)->toHaveCount(4);

        foreach ($results as $i => $result) {
            expect($result)->toContain('AllTask-' . ($i + 1));
        }
    });
});

describe('Promise::concurrent() Integration', function () {
    it('works with concurrent promise execution while limiting concurrency', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];

        $createTask = asyncFn(function (int $i) use ($mutex, &$sharedCounter, &$sharedLog) {
            $lock = await($mutex->acquire());

            $oldValue = $sharedCounter;
            await(delay(0.01));
            $sharedCounter++;
            $sharedLog[] = "ConcTask-$i: $oldValue -> {$sharedCounter}";

            $lock->release();

            return "ConcTask-$i completed";
        });

        for ($i = 1; $i <= 6; $i++) {
            $tasks[] = fn () => $createTask($i);
        }

        $results = await(Promise::concurrent($tasks, 3));

        expect($sharedCounter)->toBe(6);
        expect($results)->toHaveCount(6);
        expect($sharedLog)->toHaveCount(6);

        for ($i = 0; $i < 6; $i++) {
            expect($sharedLog[$i])->toContain("$i -> " . ($i + 1));
        }
    });
});

describe('Promise::batch() Integration', function () {
    it('processes batches correctly with mutex protection', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];

        $createTask = asyncFn(function (int $i) use ($mutex, &$sharedCounter, &$sharedLog) {
            $lock = await($mutex->acquire());

            $oldValue = $sharedCounter;
            await(delay(0.01));
            $sharedCounter++;
            $sharedLog[] = "BatchTask-$i: $oldValue -> {$sharedCounter}";

            $lock->release();

            return "BatchTask-$i done";
        });

        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = fn () => $createTask($i);
        }

        $results = await(Promise::batch($tasks, batchSize: 2, concurrency: 2));

        expect($sharedCounter)->toBe(5);
        expect($results)->toHaveCount(5);
        expect($sharedLog)->toHaveCount(5);
    });
});

describe('Multiple Mutexes', function () {
    it('allows independent protection of different shared resources', function () {
        $resource1 = 0;
        $resource2 = 0;
        $mutex1 = new Mutex();
        $mutex2 = new Mutex();

        $tasks = [];
        for ($i = 1; $i <= 3; $i++) {
            $tasks[] = async(function () use ($i, $mutex1, $mutex2, &$resource1, &$resource2) {
                $lock1 = await($mutex1->acquire());
                $resource1 += $i;
                $lock1->release();

                await(delay(0.01));

                $lock2 = await($mutex2->acquire());
                $resource2 += $i * 2;
                $lock2->release();

                return "MultiTask $i completed";
            });
        }

        $results = await(Promise::all($tasks));

        expect($resource1)->toBe(6);
        expect($resource2)->toBe(12);
        expect($results)->toHaveCount(3);
    });
});

describe('Mutex Queueing', function () {
    it('properly queues and processes waiting acquire requests', function () {
        $mutex = new Mutex();

        $firstLock = await($mutex->acquire());
        expect($mutex->isLocked())->toBeTrue();
        expect($mutex->queueLength)->toBe(0);

        $secondPromise = $mutex->acquire();
        $thirdPromise = $mutex->acquire();
        expect($mutex->queueLength)->toBe(2);

        $firstLock->release();
        expect($mutex->isLocked())->toBeTrue();
        expect($mutex->queueLength)->toBe(1);

        $secondLock = await($secondPromise);
        expect($secondLock)->toBe($mutex);
        $secondLock->release();
        expect($mutex->queueLength)->toBe(0);

        $thirdLock = await($thirdPromise);
        $thirdLock->release();
        expect($mutex->isLocked())->toBeFalse();
        expect($mutex->isQueueEmpty())->toBeTrue();
    });
});
