<?php

declare(strict_types=1);

use function Hibla\async;

use function Hibla\asyncFn;

use function Hibla\await;
use function Hibla\delay;
use function Hibla\Promise\all;
use function Hibla\Promise\allSettled;
use function Hibla\Promise\batch;
use function Hibla\Promise\concurrent;
use function Hibla\Promise\race;
use function Hibla\Promise\timeout;

use Hibla\Sync\Mutex;

describe('Promise::all() with Mutex', function () {
    it('protects shared resources across all promises', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];

        $protectedWork = function (string $taskName) use ($mutex, &$sharedCounter, &$sharedLog) {
            return function () use ($taskName, $mutex, &$sharedCounter, &$sharedLog) {
                $lock = await($mutex->acquire());

                $oldValue = $sharedCounter;
                await(delay(0.02));
                $sharedCounter++;
                $sharedLog[] = "$taskName: $oldValue -> {$sharedCounter}";

                $lock->release();

                return "$taskName completed (result: {$sharedCounter})";
            };
        };

        $tasks = [];
        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = async($protectedWork("Task-$i"));
        }

        $results = await(all($tasks));

        expect($sharedCounter)->toBe(5);
        expect(count($results))->toBe(5);
        expect(count($sharedLog))->toBe(5);

        for ($i = 0; $i < 5; $i++) {
            expect($sharedLog[$i])->toContain("$i -> " . ($i + 1));
        }
    });
});

describe('Promise::race() with Mutex', function () {
    it('protects shared resources even in race conditions', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];

        $protectedWork = function (string $taskName) use ($mutex, &$sharedCounter, &$sharedLog) {
            return function () use ($taskName, $mutex, &$sharedCounter, &$sharedLog) {
                $lock = await($mutex->acquire());

                $oldValue = $sharedCounter;
                await(delay(0.02));
                $sharedCounter++;
                $sharedLog[] = "$taskName: $oldValue -> {$sharedCounter}";

                $lock->release();

                return "$taskName completed (result: {$sharedCounter})";
            };
        };

        $raceTasks = [
            async(function () use ($protectedWork) {
                await(delay(0.1));

                return await(async($protectedWork('Slow-Task')));
            }),
            async(function () use ($protectedWork) {
                await(delay(0.05));

                return await(async($protectedWork('Fast-Task')));
            }),
            async(function () use ($protectedWork) {
                await(delay(0.07));

                return await(async($protectedWork('Medium-Task')));
            }),
        ];

        $winner = await(race($raceTasks));

        expect($winner)->toContain('Fast-Task completed');
        expect($sharedCounter)->toBeGreaterThan(0);
        expect(count($sharedLog))->toBeGreaterThan(0);
    });
});

describe('Promise::concurrent() with Mutex', function () {
    it('limits concurrency while protecting shared resources', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];

        $createTask = asyncFn(function (int $i) use ($mutex, &$sharedCounter, &$sharedLog) {
            $lock = await($mutex->acquire());

            $oldValue = $sharedCounter;
            await(delay(0.02));
            $sharedCounter++;
            $sharedLog[] = "Concurrent-$i: $oldValue -> {$sharedCounter}";

            $lock->release();

            return "Concurrent-$i completed (result: {$sharedCounter})";
        });

        for ($i = 1; $i <= 8; $i++) {
            $tasks[] = fn () => $createTask($i);
        }

        $results = await(concurrent($tasks, 3));

        expect($sharedCounter)->toBe(8);
        expect(count($results))->toBe(8);
        expect(count($sharedLog))->toBe(8);

        for ($i = 0; $i < 8; $i++) {
            expect($sharedLog[$i])->toContain("$i -> " . ($i + 1));
        }
    });
});

describe('Promise::batch() with Mutex', function () {
    it('processes batches while protecting shared resources', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];
        $tasks = [];

        $createTask = asyncFn(function (int $i) use ($mutex, &$sharedCounter, &$sharedLog) {
            $lock = await($mutex->acquire());

            $oldValue = $sharedCounter;
            await(delay(0.02));
            $sharedCounter++;
            $sharedLog[] = "Batch-$i: $oldValue -> {$sharedCounter}";

            $lock->release();

            return "Batch-$i completed (result: {$sharedCounter})";
        });

        for ($i = 1; $i <= 6; $i++) {
            $tasks[] = fn () => $createTask($i);
        }

        $results = await(batch($tasks, 3, 2));

        expect($sharedCounter)->toBe(6);
        expect(count($results))->toBe(6);
        expect(count($sharedLog))->toBe(6);
    });
});

describe('Promise::allSettled() with Mutex', function () {
    it('handles mixed success/failure with mutex protection', function () {
        $mutex = new Mutex();
        $sharedCounter = 0;
        $sharedLog = [];

        $protectedWork = function (string $taskName) use ($mutex, &$sharedCounter, &$sharedLog) {
            return function () use ($taskName, $mutex, &$sharedCounter, &$sharedLog) {
                $lock = await($mutex->acquire());

                $oldValue = $sharedCounter;
                await(delay(0.02));
                $sharedCounter++;
                $sharedLog[] = "$taskName: $oldValue -> {$sharedCounter}";

                $lock->release();

                return "$taskName completed (result: {$sharedCounter})";
            };
        };

        $tasks = [
            async($protectedWork('Success-1')),
            async(function () use ($mutex, &$sharedCounter, &$sharedLog) {
                $lock = await($mutex->acquire());
                $sharedCounter++;
                $sharedLog[] = "Failure task: counter = {$sharedCounter}";
                $lock->release();

                throw new Exception('Intentional failure');
            }),
            async($protectedWork('Success-2')),
        ];

        $results = await(allSettled($tasks));

        expect(count($results))->toBe(3);
        expect($results[0]['status'])->toBe('fulfilled');
        expect($results[1]['status'])->toBe('rejected');
        expect($results[2]['status'])->toBe('fulfilled');
        expect($sharedCounter)->toBe(3);
    });
});

describe('Multiple Mutexes', function () {
    it('allows independent protection of different resources', function () {
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

                return "Task $i completed";
            });
        }

        await(all($tasks));

        expect($resource1)->toBe(6);
        expect($resource2)->toBe(12);
    });
});

describe('Timeout with Mutex', function () {
    it('handles timeouts properly with mutex operations', function () {
        $mutex = new Mutex();
        $timeoutCounter = 0;

        expect(function () use ($mutex, &$timeoutCounter) {
            $timeoutTask = async(function () use ($mutex, &$timeoutCounter) {
                $lock = await($mutex->acquire());
                await(delay(1.0));
                $timeoutCounter++;
                $lock->release();

                return 'Should not complete';
            });

            await(timeout($timeoutTask, 0.1));
        })->toThrow(Exception::class);

        expect($timeoutCounter)->toBeLessThanOrEqual(1);
    });
});
