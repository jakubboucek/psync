<?php

declare(strict_types=1);

use PhpSync\Sync\IgnoreMatcher;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('ukotvený vzor matchuje cestu i podstrom, ne jinde', function () {
    $m = new IgnoreMatcher(['/temp']);
    Assert::true($m->matches('temp'));
    Assert::true($m->matches('temp/cache/x'));
    Assert::true($m->matches('/temp/cache'));     // vodicí lomítko se normalizuje
    Assert::false($m->matches('app/temp'));        // ukotveno k rootu
    Assert::false($m->matches('temporary'));
});


test('neukotvený glob matchuje basename i segment', function () {
    $m = new IgnoreMatcher(['*.log']);
    Assert::true($m->matches('error.log'));
    Assert::true($m->matches('var/logs/error.log'));
    Assert::false($m->matches('error.txt'));

    $git = new IgnoreMatcher(['.git']);
    Assert::true($git->matches('.git'));
    Assert::true($git->matches('sub/.git/config'));
});


test('ukotvený glob přes celou cestu', function () {
    $m = new IgnoreMatcher(['/vendor']);
    Assert::true($m->matches('vendor/autoload.php'));
    Assert::false($m->matches('app/vendor/x'));
});


test('prázdný matcher nematchuje nic', function () {
    $m = new IgnoreMatcher([]);
    Assert::false($m->matches('cokoliv'));
    Assert::false($m->matches('a/b/c.txt'));
    Assert::true($m->isEmpty());
});


test('více vzorů – stačí jeden', function () {
    $m = new IgnoreMatcher(['/dist', '*.tmp', '.DS_Store']);
    Assert::true($m->matches('dist/app.js'));
    Assert::true($m->matches('build/x.tmp'));
    Assert::true($m->matches('a/b/.DS_Store'));
    Assert::false($m->matches('src/app.php'));
});
