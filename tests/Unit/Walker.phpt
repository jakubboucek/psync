<?php

declare(strict_types=1);

use JakubBoucek\Psync\Sync\FileType;
use JakubBoucek\Psync\Sync\IgnoreMatcher;
use JakubBoucek\Psync\Sync\Walker;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** Builds a fixture tree in a fresh temp dir and returns its root. */
function makeTree(): string
{
    $root = sys_get_temp_dir() . '/psync_walk_' . bin2hex(random_bytes(4));
    mkdir($root . '/sub', 0777, true);
    mkdir($root . '/empty', 0777, true);          // empty directory
    mkdir($root . '/temp', 0777, true);
    mkdir($root . '/keepdir', 0777, true);
    file_put_contents($root . '/a.txt', "alpha\n");
    file_put_contents($root . '/sub/b.txt', "beta\n");
    file_put_contents($root . '/temp/x.log', "log\n");
    file_put_contents($root . '/keepdir/y.txt', "y\n");
    return $root;
}

/**
 * @return array<string, FileType> rel => type
 */
function walkMap(string $root, array $ignore): array
{
    $walker = new Walker($root, new IgnoreMatcher($ignore));
    $map = [];
    foreach ($walker->walk() as $entry) {
        $map[$entry->path] = $entry->type;
    }
    return $map;
}


test('directories are listed as first-class entries (incl. empty ones)', function () {
    $root = makeTree();
    $map = walkMap($root, []);

    // Directories appear as Dir entries...
    Assert::same(FileType::Dir, $map['sub'] ?? null);
    Assert::same(FileType::Dir, $map['empty'] ?? null);   // empty dir is listed
    Assert::same(FileType::Dir, $map['temp'] ?? null);
    Assert::same(FileType::Dir, $map['keepdir'] ?? null);
    // ...files as File entries.
    Assert::same(FileType::File, $map['a.txt'] ?? null);
    Assert::same(FileType::File, $map['sub/b.txt'] ?? null);

    rrmdir($root);
});


test("ignore '/temp' drops the directory entry and its whole subtree", function () {
    $root = makeTree();
    $map = walkMap($root, ['/temp']);

    Assert::false(isset($map['temp']));        // the folder itself is gone
    Assert::false(isset($map['temp/x.log']));  // and its contents
    Assert::true(isset($map['empty']));        // unrelated dirs stay

    rrmdir($root);
});


test("ignore '/keepdir/*' keeps the directory but drops its contents", function () {
    $root = makeTree();
    $map = walkMap($root, ['/keepdir/*']);

    Assert::same(FileType::Dir, $map['keepdir'] ?? null);  // folder is kept (will be created)
    Assert::false(isset($map['keepdir/y.txt']));           // contents ignored

    rrmdir($root);
});


/** Recursively removes a fixture tree. */
function rrmdir(string $dir): void
{
    foreach (scandir($dir) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}
