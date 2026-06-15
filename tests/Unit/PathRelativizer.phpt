<?php

declare(strict_types=1);

use JakubBoucek\Psync\Sync\PathRelativizer;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('same directory yields an empty path', function () {
    Assert::same('', PathRelativizer::relativize('', ''));
    Assert::same('', PathRelativizer::relativize('.', '.'));
    Assert::same('', PathRelativizer::relativize('www', 'www'));
    Assert::same('', PathRelativizer::relativize('a/b/c', 'a/b/c'));
});


test('descending into a subdirectory (app inside web)', function () {
    // agent at project-root, sync only system/logs
    Assert::same('system/logs', PathRelativizer::relativize('', 'system/logs'));
    Assert::same('logs', PathRelativizer::relativize('system', 'system/logs'));
});


test('climbing up to a parent (web inside app)', function () {
    // agent in www, sync the whole app above it (Nette-style)
    Assert::same('..', PathRelativizer::relativize('www', ''));
    Assert::same('../..', PathRelativizer::relativize('web/public', ''));
    Assert::same('..', PathRelativizer::relativize('a/b', 'a'));
});


test('divergent branches (agent and sync-root are siblings)', function () {
    // agent in tools, manage system/logs
    Assert::same('../system/logs', PathRelativizer::relativize('tools', 'system/logs'));
    Assert::same('../../b/c', PathRelativizer::relativize('a/x/y', 'a/b/c'));
});


test('normalize collapses empty and dot segments', function () {
    Assert::same('', PathRelativizer::normalize(''));
    Assert::same('', PathRelativizer::normalize('.'));
    Assert::same('', PathRelativizer::normalize('./'));
    Assert::same('a/b', PathRelativizer::normalize('a//b/'));
    Assert::same('a/b', PathRelativizer::normalize('./a/./b'));
});


test('leading/trailing slashes do not affect the result', function () {
    Assert::same('..', PathRelativizer::relativize('/www/', '/'));
    Assert::same('system/logs', PathRelativizer::relativize('/', '/system/logs/'));
});
