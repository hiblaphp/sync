<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

use Hibla\Sync\Semaphore;

function semaphoreTestSetup(int $permits = 3): array
{
    return [
        'semaphore' => new Semaphore($permits),
        'sharedCounter' => 0,
        'sharedLog' => [],
        'concurrentCount' => 0,
        'maxConcurrent' => 0,
    ];
}

describe('Basic Semaphore Operations', function () {
    it('starts with all permits available', function () {
        $setup = semaphoreTestSetup(5);
        $semaphore = $setup['semaphore'];

        expect($semaphore->capacity)->toBe(5);
        expect($semaphore->available)->toBe(5);
        expect($semaphore->queueLength)->toBe(0);
        expect($semaphore->isQueueEmpty())->toBeTrue();
        expect($semaphore->isIdle())->toBeTrue();
        expect($semaphore->isFull())->toBeFalse();
    });

    it('throws exception for invalid capacity', function () {
        new Semaphore(0);
    })->throws(InvalidArgumentException::class, 'Semaphore permits must be at least 1');

    it('can acquire and release permits', function () {
        $setup = semaphoreTestSetup(3);
        $semaphore = $setup['semaphore'];

        $permit1 = await($semaphore->acquire());
        expect($semaphore->available)->toBe(2);
        expect($permit1)->toBe($semaphore);

        $permit2 = await($semaphore->acquire());
        expect($semaphore->available)->toBe(1);

        $permit3 = await($semaphore->acquire());
        expect($semaphore->available)->toBe(0);
        expect($semaphore->isFull())->toBeTrue();

        $semaphore->release();
        expect($semaphore->available)->toBe(1);

        $semaphore->release();
        expect($semaphore->available)->toBe(2);

        $semaphore->release();
        expect($semaphore->available)->toBe(3);
        expect($semaphore->isIdle())->toBeTrue();
    });

    it('queues acquire attempts when full', function () {
        $setup = semaphoreTestSetup(2);
        $semaphore = $setup['semaphore'];

        $permit1 = await($semaphore->acquire());
        $permit2 = await($semaphore->acquire());
        expect($semaphore->available)->toBe(0);
        expect($semaphore->isFull())->toBeTrue();

        $permit3Promise = $semaphore->acquire();
        expect($semaphore->queueLength)->toBe(1);

        $permit4Promise = $semaphore->acquire();
        expect($semaphore->queueLength)->toBe(2);

        $permit5Promise = $semaphore->acquire();
        expect($semaphore->queueLength)->toBe(3);

        $semaphore->release();
        expect($semaphore->queueLength)->toBe(2);
        expect($semaphore->available)->toBe(0);

        $semaphore->release();
        expect($semaphore->queueLength)->toBe(1);

        $permit3 = await($permit3Promise);
        $permit3->release();
        expect($semaphore->queueLength)->toBe(0);

        $permit4 = await($permit4Promise);
        $permit4->release();

        $permit5 = await($permit5Promise);
        $permit5->release();

        expect($semaphore->available)->toBe(2);
        expect($semaphore->isQueueEmpty())->toBeTrue();
    });

    it('throws exception when releasing more than capacity', function () {
        $setup = semaphoreTestSetup(2);
        $semaphore = $setup['semaphore'];

        $semaphore->release();
        $semaphore->release();
        $semaphore->release();
    })->throws(LogicException::class, 'Cannot release more permits than semaphore capacity');
});

describe('Concurrent Access Control', function () {
    it('limits concurrent access to specified capacity', function () {
        $setup = semaphoreTestSetup(3);
        $semaphore = $setup['semaphore'];
        $concurrentCount = &$setup['concurrentCount'];
        $maxConcurrent = &$setup['maxConcurrent'];

        $tasks = [];

        for ($i = 1; $i <= 10; $i++) {
            $tasks[] = async(function () use ($i, $semaphore, &$concurrentCount, &$maxConcurrent) {
                $permit = await($semaphore->acquire());

                $concurrentCount++;
                $maxConcurrent = max($maxConcurrent, $concurrentCount);

                await(delay(0.05));

                $concurrentCount--;
                $permit->release();

                return "Task-$i completed";
            });
        }

        foreach ($tasks as $task) {
            await($task);
        }

        expect($maxConcurrent)->toBe(3);
        expect($concurrentCount)->toBe(0);
        expect($semaphore->available)->toBe(3);
        expect($semaphore->isIdle())->toBeTrue();
    });

    it('handles quick succession acquire/release', function () {
        $setup = semaphoreTestSetup(5);
        $semaphore = $setup['semaphore'];

        for ($i = 1; $i <= 20; $i++) {
            $permit = await($semaphore->acquire());
            expect($semaphore->available)->toBeLessThan(5);
            $permit->release();
        }

        expect($semaphore->available)->toBe(5);
        expect($semaphore->isIdle())->toBeTrue();
        expect($semaphore->isQueueEmpty())->toBeTrue();
    });
});

describe('Semaphore State Inspection', function () {
    it('correctly reports available permits', function () {
        $setup = semaphoreTestSetup(4);
        $semaphore = $setup['semaphore'];

        expect($semaphore->available)->toBe(4);

        await($semaphore->acquire());
        expect($semaphore->available)->toBe(3);

        await($semaphore->acquire());
        expect($semaphore->available)->toBe(2);

        $semaphore->release();
        expect($semaphore->available)->toBe(3);

        $semaphore->release();
        expect($semaphore->available)->toBe(4);
    });

    it('correctly reports queue length', function () {
        $setup = semaphoreTestSetup(2);
        $semaphore = $setup['semaphore'];

        await($semaphore->acquire());
        await($semaphore->acquire());
        expect($semaphore->queueLength)->toBe(0);

        $promise1 = $semaphore->acquire();
        expect($semaphore->queueLength)->toBe(1);

        $promise2 = $semaphore->acquire();
        expect($semaphore->queueLength)->toBe(2);

        $promise3 = $semaphore->acquire();
        expect($semaphore->queueLength)->toBe(3);

        $semaphore->release();
        expect($semaphore->queueLength)->toBe(2);

        $semaphore->release();
        expect($semaphore->queueLength)->toBe(1);

        $permit1 = await($promise1);
        $permit1->release();
        expect($semaphore->queueLength)->toBe(0);

        $permit2 = await($promise2);
        $permit2->release();

        $permit3 = await($promise3);
        $permit3->release();

        expect($semaphore->isQueueEmpty())->toBeTrue();
    });

    it('correctly reports full and empty states', function () {
        $setup = semaphoreTestSetup(2);
        $semaphore = $setup['semaphore'];

        expect($semaphore->isIdle())->toBeTrue();
        expect($semaphore->isFull())->toBeFalse();

        await($semaphore->acquire());
        expect($semaphore->isIdle())->toBeFalse();
        expect($semaphore->isFull())->toBeFalse();

        await($semaphore->acquire());
        expect($semaphore->isIdle())->toBeFalse();
        expect($semaphore->isFull())->toBeTrue();

        $semaphore->release();
        expect($semaphore->isIdle())->toBeFalse();
        expect($semaphore->isFull())->toBeFalse();

        $semaphore->release();
        expect($semaphore->isIdle())->toBeTrue();
        expect($semaphore->isFull())->toBeFalse();
    });
});

describe('Try Acquire Operations', function () {
    it('successfully tries to acquire when permits available', function () {
        $setup = semaphoreTestSetup(2);
        $semaphore = $setup['semaphore'];

        expect($semaphore->tryAcquire())->toBeTrue();
        expect($semaphore->available)->toBe(1);

        expect($semaphore->tryAcquire())->toBeTrue();
        expect($semaphore->available)->toBe(0);
    });

    it('fails to try acquire when no permits available', function () {
        $setup = semaphoreTestSetup(1);
        $semaphore = $setup['semaphore'];

        expect($semaphore->tryAcquire())->toBeTrue();
        expect($semaphore->available)->toBe(0);

        expect($semaphore->tryAcquire())->toBeFalse();
        expect($semaphore->available)->toBe(0);

        $semaphore->release();
        expect($semaphore->tryAcquire())->toBeTrue();
    });

    it('does not queue when try acquire fails', function () {
        $setup = semaphoreTestSetup(1);
        $semaphore = $setup['semaphore'];

        await($semaphore->acquire());

        expect($semaphore->tryAcquire())->toBeFalse();
        expect($semaphore->queueLength)->toBe(0);

        $semaphore->acquire();
        expect($semaphore->queueLength)->toBe(1);
    });
});

describe('Multiple Permits Operations', function () {
    it('acquires and releases multiple permits', function () {
        $setup = semaphoreTestSetup(10);
        $semaphore = $setup['semaphore'];

        $permit = await($semaphore->acquireMany(3));
        expect($semaphore->available)->toBe(7);

        $semaphore->releaseMany(3);
        expect($semaphore->available)->toBe(10);
    });

    it('throws exception when acquiring more permits than capacity', function () {
        $setup = semaphoreTestSetup(5);
        $semaphore = $setup['semaphore'];

        $semaphore->acquireMany(6);
    })->throws(InvalidArgumentException::class);

    it('throws exception when acquiring less than 1 permit', function () {
        $setup = semaphoreTestSetup(5);
        $semaphore = $setup['semaphore'];

        $semaphore->acquireMany(0);
    })->throws(InvalidArgumentException::class, 'Must acquire at least 1 permit');

    it('throws exception when releasing less than 1 permit', function () {
        $setup = semaphoreTestSetup(5);
        $semaphore = $setup['semaphore'];

        $semaphore->releaseMany(0);
    })->throws(InvalidArgumentException::class, 'Must release at least 1 permit');

    it('queues when acquiring multiple permits not available', function () {
        $setup = semaphoreTestSetup(5);
        $semaphore = $setup['semaphore'];

        await($semaphore->acquireMany(4));
        expect($semaphore->available)->toBe(1);

        $promise = $semaphore->acquireMany(3);
        // one entry with needed=3, not 3 duplicate entries
        expect($semaphore->queueLength)->toBe(1);

        $semaphore->releaseMany(4);
        expect($semaphore->queueLength)->toBe(0);

        $permit = await($promise);
        expect($semaphore->available)->toBe(2);

        $semaphore->releaseMany(3);
        expect($semaphore->available)->toBe(5);
    });

    it('handles concurrent multiple permit acquisitions', function () {
        $setup = semaphoreTestSetup(10);
        $semaphore = $setup['semaphore'];
        $sharedLog = &$setup['sharedLog'];

        $task1 = async(function () use ($semaphore, &$sharedLog) {
            $permit = await($semaphore->acquireMany(5));
            $sharedLog[] = 'Task-1 acquired 5 permits';
            await(delay(0.05));
            $semaphore->releaseMany(5);
            $sharedLog[] = 'Task-1 released 5 permits';
        });

        $task2 = async(function () use ($semaphore, &$sharedLog) {
            $permit = await($semaphore->acquireMany(3));
            $sharedLog[] = 'Task-2 acquired 3 permits';
            await(delay(0.05));
            $semaphore->releaseMany(3);
            $sharedLog[] = 'Task-2 released 3 permits';
        });

        $task3 = async(function () use ($semaphore, &$sharedLog) {
            await(delay(0.02));
            $permit = await($semaphore->acquireMany(8));
            $sharedLog[] = 'Task-3 acquired 8 permits';
            await(delay(0.03));
            $semaphore->releaseMany(8);
            $sharedLog[] = 'Task-3 released 8 permits';
        });

        await($task1);
        await($task2);
        await($task3);

        expect(count($sharedLog))->toBe(6);
        expect($semaphore->available)->toBe(10);
        expect($semaphore->isIdle())->toBeTrue();
    });
});

describe('Real-world Usage Patterns', function () {
    it('simulates connection pool management', function () {
        $setup = semaphoreTestSetup(5);
        $semaphore = $setup['semaphore'];
        $activeConnections = 0;
        $peakConnections = 0;
        $totalRequests = 0;

        $tasks = [];

        for ($i = 1; $i <= 15; $i++) {
            $tasks[] = async(function () use ($i, $semaphore, &$activeConnections, &$peakConnections, &$totalRequests) {
                $permit = await($semaphore->acquire());

                $activeConnections++;
                $peakConnections = max($peakConnections, $activeConnections);
                $totalRequests++;

                await(delay(0.03));

                $activeConnections--;
                $permit->release();

                return "Request-$i completed";
            });
        }

        foreach ($tasks as $task) {
            await($task);
        }

        expect($totalRequests)->toBe(15);
        expect($peakConnections)->toBe(5);
        expect($activeConnections)->toBe(0);
        expect($semaphore->available)->toBe(5);
    });

    it('simulates rate-limited API calls', function () {
        $setup = semaphoreTestSetup(3);
        $semaphore = $setup['semaphore'];
        $apiCalls = [];
        $startTime = microtime(true);

        $tasks = [];

        for ($i = 1; $i <= 9; $i++) {
            $tasks[] = async(function () use ($i, $semaphore, &$apiCalls, $startTime) {
                $permit = await($semaphore->acquire());

                $timestamp = microtime(true) - $startTime;
                $apiCalls[] = ['id' => $i, 'timestamp' => $timestamp];

                await(delay(0.1));

                $permit->release();

                return "API-Call-$i completed";
            });
        }

        foreach ($tasks as $task) {
            await($task);
        }

        expect(count($apiCalls))->toBe(9);
        expect($semaphore->available)->toBe(3);

        $batches = [
            array_slice($apiCalls, 0, 3),
            array_slice($apiCalls, 3, 3),
            array_slice($apiCalls, 6, 3),
        ];

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $call) {
                $expectedMinTime = $batchIndex * 0.1;
                expect($call['timestamp'])->toBeGreaterThanOrEqual($expectedMinTime - 0.01);
            }
        }
    });
});
