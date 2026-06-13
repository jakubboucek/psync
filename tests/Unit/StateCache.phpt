<?php

declare(strict_types=1);

use JakubBoucek\Psync\State\StateCache;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('lookup returns a verdict only when size+mtime match on both sides', function () {
    $file = tempnam(sys_get_temp_dir(), 'psync_state_');
    $cache = new StateCache($file);

    $cache->store('a.txt', 100, 1000, 2000, 'abc123', true);

    Assert::true($cache->lookup('a.txt', 100, 1000, 2000));  // exact match
    Assert::null($cache->lookup('a.txt', 101, 1000, 2000));  // different local size
    Assert::null($cache->lookup('a.txt', 100, 1001, 2000));  // different local mtime
    Assert::null($cache->lookup('a.txt', 100, 1000, 2001));  // different remote mtime
    Assert::null($cache->lookup('jiny.txt', 100, 1000, 2000)); // unknown

    @unlink($file);
});


test('a false verdict is retained and survives save/reload', function () {
    $file = tempnam(sys_get_temp_dir(), 'psync_state_');

    $a = new StateCache($file);
    $a->store('x', 5, 10, 20, 'md5x', false);
    $a->save();

    $b = new StateCache($file); // a new instance loads from disk
    Assert::false($b->lookup('x', 5, 10, 20));

    @unlink($file);
});


test('nonexistent cache file → empty, no error', function () {
    $cache = new StateCache(sys_get_temp_dir() . '/psync_neexistuje_' . uniqid() . '.json');
    Assert::null($cache->lookup('anything', 1, 2, 3));
});
