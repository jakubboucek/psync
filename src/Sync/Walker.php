<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

/**
 * Local recursive file traversal - mirrors the agent's behavior (deterministic,
 * does not follow symlinks), and additionally applies ignore patterns.
 */
final readonly class Walker
{
    private string $root;

    public function __construct(string $root, private IgnoreMatcher $ignore)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * @return iterable<FileEntry>
     */
    public function walk(string $scope = ''): iterable
    {
        $scope = trim($scope, '/');
        $base = $scope === '' ? $this->root : $this->root . '/' . $scope;

        if (is_file($base)) {
            $st = @stat($base);
            if ($st !== false && !$this->ignore->matches($scope)) {
                yield new FileEntry($scope, (int) $st['size'], (int) $st['mtime']);
            }
            return;
        }
        if (!is_dir($base)) {
            return;
        }
        yield from $this->walkDir($base);
    }

    /**
     * @return iterable<FileEntry>
     */
    private function walkDir(string $dir): iterable
    {
        $rootLen = strlen($this->root) + 1;
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        sort($entries, SORT_STRING);

        $subdirs = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            $rel = substr($full, $rootLen);
            if ($this->ignore->matches($rel)) {
                continue;
            }
            if (is_dir($full)) {
                $subdirs[] = $full;
            } elseif (is_file($full)) {
                $st = @stat($full);
                if ($st !== false) {
                    yield new FileEntry($rel, (int) $st['size'], (int) $st['mtime']);
                }
            }
        }
        foreach ($subdirs as $sd) {
            yield from $this->walkDir($sd);
        }
    }
}
