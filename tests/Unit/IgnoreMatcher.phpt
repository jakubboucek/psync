<?php

declare(strict_types=1);

use PhpSync\Sync\IgnoreMatcher;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('anchored pattern matches the path and subtree, not elsewhere', function () {
    $m = new IgnoreMatcher(['/temp']);
    Assert::true($m->matches('temp'));
    Assert::true($m->matches('temp/cache/x'));
    Assert::true($m->matches('/temp/cache'));     // leading slash is normalized
    Assert::false($m->matches('app/temp'));        // anchored to the root
    Assert::false($m->matches('temporary'));
});


test('unanchored glob matches the basename and a segment', function () {
    $m = new IgnoreMatcher(['*.log']);
    Assert::true($m->matches('error.log'));
    Assert::true($m->matches('var/logs/error.log'));
    Assert::false($m->matches('error.txt'));

    $git = new IgnoreMatcher(['.git']);
    Assert::true($git->matches('.git'));
    Assert::true($git->matches('sub/.git/config'));
});


test('anchored glob across the whole path', function () {
    $m = new IgnoreMatcher(['/vendor']);
    Assert::true($m->matches('vendor/autoload.php'));
    Assert::false($m->matches('app/vendor/x'));
});


test('empty matcher matches nothing', function () {
    $m = new IgnoreMatcher([]);
    Assert::false($m->matches('anything'));
    Assert::false($m->matches('a/b/c.txt'));
    Assert::true($m->isEmpty());
});


test('multiple patterns - one is enough', function () {
    $m = new IgnoreMatcher(['/dist', '*.tmp', '.DS_Store']);
    Assert::true($m->matches('dist/app.js'));
    Assert::true($m->matches('build/x.tmp'));
    Assert::true($m->matches('a/b/.DS_Store'));
    Assert::false($m->matches('src/app.php'));
});
