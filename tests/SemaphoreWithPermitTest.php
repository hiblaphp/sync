<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

use Hibla\Promise\Promise;
use Hibla\Sync\Semaphore;

describe('Semaphore::withPermit()', function () {

    it('acquires and releases permit automatically for sync callable', function () {
        $semaphore = new Semaphore(2);

        $result = await($semaphore->withPermit(function () use ($semaphore) {
            expect($semaphore->available)->toBe(1);

            return 'sync result';
        }));

        expect($result)->toBe('sync result');
        expect($semaphore->available)->toBe(2);
    });

    it('acquires and releases permit automatically for async callable', function () {
        $semaphore = new Semaphore(2);

        $result = await($semaphore->withPermit(function () use ($semaphore) {
            return async(function () use ($semaphore) {
                expect($semaphore->available)->toBe(1);
                await(delay(0.05));
                expect($semaphore->available)->toBe(1);

                return 'async result';
            });
        }));

        expect($result)->toBe('async result');
        expect($semaphore->available)->toBe(2);
    });

    it('releases permit when sync callable throws', function () {
        $semaphore = new Semaphore(2);

        expect(fn () => await($semaphore->withPermit(function () {
            throw new RuntimeException('sync error');
        })))->toThrow(RuntimeException::class, 'sync error');

        expect($semaphore->available)->toBe(2);
    });

    it('releases permit when async callable rejects', function () {
        $semaphore = new Semaphore(2);

        expect(fn () => await($semaphore->withPermit(function () {
            return async(function () {
                await(delay(0.05));

                throw new RuntimeException('async error');
            });
        })))->toThrow(RuntimeException::class, 'async error');

        expect($semaphore->available)->toBe(2);
    });

    it('releases permit when outer promise is cancelled', function () {
        $semaphore = new Semaphore(1);

        $outer = $semaphore->withPermit(function () {
            return async(function () {
                await(delay(1.0));

                return 'should not complete';
            });
        });

        $outer->catch(static fn () => null);

        $check = async(function () use ($semaphore, $outer) {
            await(delay(0.1));
            expect($semaphore->available)->toBe(0);

            $outer->cancel();

            await(delay(0.1));
            expect($semaphore->available)->toBe(1);
        });

        await($check);
    });

    it('limits concurrency correctly across concurrent withPermit callers', function () {
        $semaphore = new Semaphore(2);
        $concurrent = 0;
        $maxConcurrent = 0;

        $tasks = [];
        for ($i = 0; $i < 6; $i++) {
            $tasks[] = $semaphore->withPermit(function () use (&$concurrent, &$maxConcurrent) {
                return async(function () use (&$concurrent, &$maxConcurrent) {
                    $concurrent++;
                    $maxConcurrent = max($maxConcurrent, $concurrent);
                    await(delay(0.05));
                    $concurrent--;
                });
            });
        }

        await(Promise::all($tasks));

        expect($maxConcurrent)->toBe(2);
        expect($concurrent)->toBe(0);
        expect($semaphore->available)->toBe(2);
    });
});

describe('Semaphore::withPermits()', function () {

    it('acquires N permits and releases them automatically', function () {
        $semaphore = new Semaphore(5);

        $result = await($semaphore->withPermits(3, function () use ($semaphore) {
            expect($semaphore->available)->toBe(2);

            return 'held 3';
        }));

        expect($result)->toBe('held 3');
        expect($semaphore->available)->toBe(5);
    });

    it('acquires N permits for async callable and releases after resolve', function () {
        $semaphore = new Semaphore(5);

        $result = await($semaphore->withPermits(3, function () use ($semaphore) {
            return async(function () use ($semaphore) {
                expect($semaphore->available)->toBe(2);
                await(delay(0.05));
                expect($semaphore->available)->toBe(2);

                return 'async held 3';
            });
        }));

        expect($result)->toBe('async held 3');
        expect($semaphore->available)->toBe(5);
    });

    it('releases N permits when async callable rejects', function () {
        $semaphore = new Semaphore(5);

        expect(fn () => await($semaphore->withPermits(3, function () {
            return async(function () {
                await(delay(0.05));

                throw new RuntimeException('async error');
            });
        })))->toThrow(RuntimeException::class, 'async error');

        expect($semaphore->available)->toBe(5);
    });

    it('releases N permits when outer promise is cancelled', function () {
        $semaphore = new Semaphore(5);

        $outer = $semaphore->withPermits(3, function () {
            return async(function () {
                await(delay(1.0));
            });
        });

        $outer->catch(static fn () => null);

        $check = async(function () use ($semaphore, $outer) {
            await(delay(0.1));
            expect($semaphore->available)->toBe(2);

            $outer->cancel();

            await(delay(0.1));
            expect($semaphore->available)->toBe(5);
        });

        await($check);
    });

    it('queues withPermits correctly when not enough permits available', function () {
        $semaphore = new Semaphore(4);
        $log = [];

        $task1 = $semaphore->withPermits(3, function () use (&$log) {
            return async(function () use (&$log) {
                $log[] = 'task1 start (3 permits)';
                await(delay(0.1));
                $log[] = 'task1 end';
            });
        });

        $task2 = $semaphore->withPermits(2, function () use (&$log) {
            return async(function () use (&$log) {
                $log[] = 'task2 start (2 permits)';
                await(delay(0.05));
                $log[] = 'task2 end';
            });
        });

        await(Promise::all([$task1, $task2]));

        expect($semaphore->available)->toBe(4);

        $task1EndIndex = array_search('task1 end', $log);
        $task2StartIndex = array_search('task2 start (2 permits)', $log);
        expect($task2StartIndex)->toBeGreaterThan($task1EndIndex);
    });
});

describe('Semaphore::releaseMany() validation', function () {

    it('throws before releasing anything on over-release', function () {
        $semaphore = new Semaphore(3);

        await($semaphore->acquire());
        await($semaphore->acquire());
        await($semaphore->acquire());

        expect($semaphore->available)->toBe(0);

        expect(fn () => $semaphore->releaseMany(5))
            ->toThrow(LogicException::class, 'Cannot release more permits than semaphore capacity')
        ;

        expect($semaphore->available)->toBe(0);
    });

    it('succeeds when release count is within capacity', function () {
        $semaphore = new Semaphore(5);

        await($semaphore->acquireMany(4));
        expect($semaphore->available)->toBe(1);

        $semaphore->releaseMany(4);
        expect($semaphore->available)->toBe(5);
    });

    it('correctly satisfies queued waiters during releaseMany', function () {
        $semaphore = new Semaphore(4);
        $ran = false;

        await($semaphore->acquireMany(4));
        expect($semaphore->available)->toBe(0);

        $waiter = $semaphore->acquireMany(2);

        async(function () use ($waiter, &$ran) {
            await($waiter);
            $ran = true;
        });

        $semaphore->releaseMany(4);

        await(delay(0.05));

        expect($ran)->toBeTrue();
        expect($semaphore->available)->toBe(2);
    });
});
