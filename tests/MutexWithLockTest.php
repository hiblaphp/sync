<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

use Hibla\Promise\Promise;
use Hibla\Sync\Mutex;

describe('Mutex::withLock()', function () {

    it('acquires and releases lock automatically for sync callable', function () {
        $mutex = new Mutex();

        $result = await($mutex->withLock(function () use ($mutex) {
            expect($mutex->isLocked())->toBeTrue();

            return 'sync result';
        }));

        expect($result)->toBe('sync result');
        expect($mutex->isLocked())->toBeFalse();
    });

    it('acquires and releases lock automatically for async callable', function () {
        $mutex = new Mutex();

        $result = await($mutex->withLock(function () use ($mutex) {
            return async(function () use ($mutex) {
                expect($mutex->isLocked())->toBeTrue();
                await(delay(0.05));
                expect($mutex->isLocked())->toBeTrue();

                return 'async result';
            });
        }));

        expect($result)->toBe('async result');
        expect($mutex->isLocked())->toBeFalse();
    });

    it('releases lock when sync callable throws', function () {
        $mutex = new Mutex();

        expect(fn () => await($mutex->withLock(function () {
            throw new RuntimeException('sync error');
        })))->toThrow(RuntimeException::class, 'sync error');

        expect($mutex->isLocked())->toBeFalse();
    });

    it('releases lock when async callable rejects', function () {
        $mutex = new Mutex();

        expect(fn () => await($mutex->withLock(function () {
            return async(function () {
                await(delay(0.05));

                throw new RuntimeException('async error');
            });
        })))->toThrow(RuntimeException::class, 'async error');

        expect($mutex->isLocked())->toBeFalse();
    });

    it('releases lock when outer promise is cancelled', function () {
        $mutex = new Mutex();

        $outer = $mutex->withLock(function () {
            return async(function () {
                await(delay(1.0));

                return 'should not complete';
            });
        });

        $outer->catch(static fn () => null);

        $check = async(function () use ($mutex, $outer) {
            await(delay(0.1));
            expect($mutex->isLocked())->toBeTrue();

            $outer->cancel();

            await(delay(0.1));
            expect($mutex->isLocked())->toBeFalse();
        });

        await($check);
    });

    it('queues multiple withLock callers correctly', function () {
        $mutex = new Mutex();
        $log = [];

        $task1 = $mutex->withLock(function () use (&$log) {
            return async(function () use (&$log) {
                $log[] = 'task1 start';
                await(delay(0.05));
                $log[] = 'task1 end';

                return 1;
            });
        });

        $task2 = $mutex->withLock(function () use (&$log) {
            return async(function () use (&$log) {
                $log[] = 'task2 start';
                await(delay(0.05));
                $log[] = 'task2 end';

                return 2;
            });
        });

        $task3 = $mutex->withLock(function () use (&$log) {
            return async(function () use (&$log) {
                $log[] = 'task3 start';
                await(delay(0.05));
                $log[] = 'task3 end';

                return 3;
            });
        });

        $results = await(Promise::all([$task1, $task2, $task3]));

        expect($results)->toBe([1, 2, 3]);
        expect($mutex->isLocked())->toBeFalse();

        expect($log)->toBe([
            'task1 start',
            'task1 end',
            'task2 start',
            'task2 end',
            'task3 start',
            'task3 end',
        ]);
    });

    it('protects shared state correctly across concurrent callers', function () {
        $mutex = new Mutex();
        $counter = 0;
        $log = [];

        $tasks = [];
        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = $mutex->withLock(function () use (&$counter, &$log, $i) {
                return async(function () use (&$counter, &$log, $i) {
                    $old = $counter;
                    await(delay(0.02));
                    $counter++;
                    $log[] = "$i: $old -> {$counter}";
                });
            });
        }

        await(Promise::all($tasks));

        expect($counter)->toBe(5);
        expect($log)->toHaveCount(5);

        for ($i = 0; $i < 5; $i++) {
            expect($log[$i])->toContain("$i -> " . ($i + 1));
        }
    });
});
