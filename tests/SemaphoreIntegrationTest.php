<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\asyncFn;
use function Hibla\await;

use Hibla\Promise\Promise;

use function Hibla\sleep;

use Hibla\Sync\Semaphore;

describe('Semaphore', function () {

    it('creates semaphore with specified capacity', function () {
        $semaphore = new Semaphore(3);

        expect($semaphore->getCapacity())->toBe(3)
            ->and($semaphore->getAvailable())->toBe(3)
            ->and($semaphore->isEmpty())->toBeTrue()
            ->and($semaphore->isFull())->toBeFalse()
        ;
    });

    it('throws exception for invalid capacity', function () {
        new Semaphore(0);
    })->throws(InvalidArgumentException::class);

    it('acquires and releases permits correctly', function () {
        $semaphore = new Semaphore(2);

        $permit1 = await($semaphore->acquire());
        expect($semaphore->getAvailable())->toBe(1);

        $permit2 = await($semaphore->acquire());
        expect($semaphore->getAvailable())->toBe(0)
            ->and($semaphore->isFull())->toBeTrue()
        ;

        $semaphore->release();
        expect($semaphore->getAvailable())->toBe(1);

        $semaphore->release();
        expect($semaphore->getAvailable())->toBe(2)
            ->and($semaphore->isEmpty())->toBeTrue()
        ;
    });

    it('queues tasks when all permits are in use', function () {
        $semaphore = new Semaphore(2);
        $results = [];

        $task1 = async(function () use ($semaphore, &$results) {
            await($semaphore->acquire());
            $results[] = 'Task 1 acquired';
            sleep(0.2);
            $results[] = 'Task 1 releasing';
            $semaphore->release();
        });

        $task2 = async(function () use ($semaphore, &$results) {
            await($semaphore->acquire());
            $results[] = 'Task 2 acquired';
            sleep(0.2);
            $results[] = 'Task 2 releasing';
            $semaphore->release();
        });

        $task3 = async(function () use ($semaphore, &$results) {
            await($semaphore->acquire());
            $results[] = 'Task 3 acquired';
            sleep(0.2);
            $results[] = 'Task 3 releasing';
            $semaphore->release();
        });

        await(Promise::all([$task1, $task2, $task3]));

        expect($results)->toHaveCount(6)
            ->and($semaphore->getAvailable())->toBe(2)
        ;

        $task3AcquiredIndex = array_search('Task 3 acquired', $results);
        $task1ReleasingIndex = array_search('Task 1 releasing', $results);
        $task2ReleasingIndex = array_search('Task 2 releasing', $results);

        expect($task3AcquiredIndex)->toBeGreaterThan(min($task1ReleasingIndex, $task2ReleasingIndex));
    });

    it('limits concurrent execution to semaphore capacity', function () {
        $semaphore = new Semaphore(3);
        $concurrent = 0;
        $maxConcurrent = 0;

        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[] = async(function () use ($semaphore, &$concurrent, &$maxConcurrent) {
                await($semaphore->acquire());

                $concurrent++;
                $maxConcurrent = max($maxConcurrent, $concurrent);

                sleep(0.1);

                $concurrent--;
                $semaphore->release();

                return true;
            });
        }

        await(Promise::all($tasks));

        expect($maxConcurrent)->toBe(3)
            ->and($concurrent)->toBe(0)
            ->and($semaphore->getAvailable())->toBe(3)
        ;
    });

    it('handles tryAcquire without blocking', function () {
        $semaphore = new Semaphore(1);

        expect($semaphore->tryAcquire())->toBeTrue()
            ->and($semaphore->getAvailable())->toBe(0)
        ;

        expect($semaphore->tryAcquire())->toBeFalse()
            ->and($semaphore->getAvailable())->toBe(0)
        ;

        $semaphore->release();

        expect($semaphore->tryAcquire())->toBeTrue()
            ->and($semaphore->getAvailable())->toBe(0)
        ;
    });

    it('acquires and releases multiple permits', function () {
        $semaphore = new Semaphore(5);

        await($semaphore->acquireMany(3));
        expect($semaphore->getAvailable())->toBe(2);

        $semaphore->releaseMany(3);
        expect($semaphore->getAvailable())->toBe(5);
    });

    it('throws exception when acquiring more permits than capacity', function () {
        $semaphore = new Semaphore(3);

        $semaphore->acquireMany(5);
    })->throws(InvalidArgumentException::class);

    it('throws exception when releasing more permits than capacity', function () {
        $semaphore = new Semaphore(2);

        $semaphore->release();
        $semaphore->release();
        $semaphore->release();
    })->throws(LogicException::class);

    it('measures timing for rate-limited concurrent operations', function () {
        $semaphore = new Semaphore(2);
        $startTime = microtime(true);

        $tasks = [];
        for ($i = 0; $i < 6; $i++) {
            $tasks[] = async(function () use ($semaphore, $i) {
                await($semaphore->acquire());
                sleep(0.2);
                $semaphore->release();

                return $i;
            });
        }

        $results = await(Promise::all($tasks));
        $duration = microtime(true) - $startTime;

        expect($results)->toHaveCount(6)
            ->and($duration)->toBeGreaterThan(0.55)
            ->and($duration)->toBeLessThan(0.75)
        ;
    });

    it('tracks queue length correctly', function () {
        $semaphore = new Semaphore(1);

        expect($semaphore->getQueueLength())->toBe(0)
            ->and($semaphore->isQueueEmpty())->toBeTrue()
        ;

        await($semaphore->acquire());
        expect($semaphore->getQueueLength())->toBe(0);

        $promise1 = $semaphore->acquire();
        expect($semaphore->getQueueLength())->toBe(1)
            ->and($semaphore->isQueueEmpty())->toBeFalse()
        ;

        $promise2 = $semaphore->acquire();
        expect($semaphore->getQueueLength())->toBe(2);

        $semaphore->release();
        expect($semaphore->getQueueLength())->toBe(1);

        $semaphore->release();
        expect($semaphore->getQueueLength())->toBe(0)
            ->and($semaphore->isQueueEmpty())->toBeTrue()
        ;
    });

    it('works as connection pool limiter', function () {
        $semaphore = new Semaphore(3);
        $activeConnections = 0;
        $peakConnections = 0;
        $totalRequests = 0;

        $simulateRequest = asyncFn(function () use ($semaphore, &$activeConnections, &$peakConnections, &$totalRequests) {
            await($semaphore->acquire());

            $activeConnections++;
            $peakConnections = max($peakConnections, $activeConnections);
            $totalRequests++;

            sleep(0.15);

            $activeConnections--;
            $semaphore->release();

            return true;
        });

        $requests = [];
        for ($i = 0; $i < 12; $i++) {
            $requests[] = $simulateRequest();
        }

        await(Promise::all($requests));

        expect($totalRequests)->toBe(12)
            ->and($peakConnections)->toBe(3)
            ->and($activeConnections)->toBe(0)
            ->and($semaphore->getAvailable())->toBe(3)
        ;
    });

    it('handles multiple acquireMany requests in queue', function () {
        $semaphore = new Semaphore(5);
        $results = [];

        $task1 = async(function () use ($semaphore, &$results) {
            await($semaphore->acquireMany(3));
            $results[] = 'Task 1 has 3 permits';
            sleep(0.2);
            $semaphore->releaseMany(3);
            $results[] = 'Task 1 released 3 permits';
        });

        $task2 = async(function () use ($semaphore, &$results) {
            await($semaphore->acquireMany(2));
            $results[] = 'Task 2 has 2 permits';
            sleep(0.2);
            $semaphore->releaseMany(2);
            $results[] = 'Task 2 released 2 permits';
        });

        $task3 = async(function () use ($semaphore, &$results) {
            sleep(0.05);
            await($semaphore->acquireMany(4));
            $results[] = 'Task 3 has 4 permits';
            sleep(0.1);
            $semaphore->releaseMany(4);
            $results[] = 'Task 3 released 4 permits';
        });

        await(Promise::all([$task1, $task2, $task3]));

        expect($results)->toHaveCount(6)
            ->and($semaphore->getAvailable())->toBe(5)
        ;
    });
});
