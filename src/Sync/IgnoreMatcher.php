<?php

declare(strict_types=1);

namespace PhpSync\Sync;

/**
 * Matches relative paths against a list of patterns (ignore / protect).
 *
 * Semantics (loosely based on dg/ftp-deployment):
 *  - a pattern starting with '/' is anchored to the root: '/temp' matches 'temp' and 'temp/...'
 *  - a pattern without '/' matches the basename or any path segment: '*.log', '.git'
 *  - supports glob (fnmatch): '*', '?', '[...]'
 */
final class IgnoreMatcher
{
    /** @param list<string> $patterns */
    public function __construct(private readonly array $patterns)
    {
    }

    public function matches(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach ($this->patterns as $pattern) {
            if ($this->matchOne(trim($pattern), $rel)) {
                return true;
            }
        }
        return false;
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    private function matchOne(string $pattern, string $rel): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (str_starts_with($pattern, '/')) {
            $p = ltrim($pattern, '/');
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
            return fnmatch($p, $rel, FNM_PATHNAME) || fnmatch($p . '/*', $rel, FNM_PATHNAME);
        }

        // Unanchored pattern - basename or any segment.
        if (fnmatch($pattern, basename($rel))) {
            return true;
        }
        foreach (explode('/', $rel) as $segment) {
            if (fnmatch($pattern, $segment)) {
                return true;
            }
        }
        return false;
    }
}
