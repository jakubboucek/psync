<?php

declare(strict_types=1);

use PhpSync\State\StateCache;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('lookup vrátí verdikt jen při shodě size+mtime obou stran', function () {
    $file = tempnam(sys_get_temp_dir(), 'phpsync_state_');
    $cache = new StateCache($file);

    $cache->store('a.txt', 100, 1000, 2000, 'abc123', true);

    Assert::true($cache->lookup('a.txt', 100, 1000, 2000));  // přesná shoda
    Assert::null($cache->lookup('a.txt', 101, 1000, 2000));  // jiná lokální velikost
    Assert::null($cache->lookup('a.txt', 100, 1001, 2000));  // jiný lokální mtime
    Assert::null($cache->lookup('a.txt', 100, 1000, 2001));  // jiný remote mtime
    Assert::null($cache->lookup('jiny.txt', 100, 1000, 2000)); // neznámý

    @unlink($file);
});


test('verdikt false se uchová a přežije save/reload', function () {
    $file = tempnam(sys_get_temp_dir(), 'phpsync_state_');

    $a = new StateCache($file);
    $a->store('x', 5, 10, 20, 'md5x', false);
    $a->save();

    $b = new StateCache($file); // nová instance načte z disku
    Assert::false($b->lookup('x', 5, 10, 20));

    @unlink($file);
});


test('neexistující soubor cache → prázdná, bez chyby', function () {
    $cache = new StateCache(sys_get_temp_dir() . '/phpsync_neexistuje_' . uniqid() . '.json');
    Assert::null($cache->lookup('cokoliv', 1, 2, 3));
});
