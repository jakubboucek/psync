<?php

declare(strict_types=1);

namespace PhpSync\Sync;

/**
 * Porovnává relativní cesty proti seznamu vzorů (ignore / protect).
 *
 * Sémantika (volně dle dg/ftp-deployment):
 *  - vzor začínající '/' je ukotvený k rootu: '/temp' matchuje 'temp' i 'temp/...'
 *  - vzor bez '/' matchuje basename nebo libovolný segment cesty: '*.log', '.git'
 *  - podporuje glob (fnmatch): '*', '?', '[...]'
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

        // Neukotvený vzor – basename nebo libovolný segment.
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
