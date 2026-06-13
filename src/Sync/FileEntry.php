<?php

declare(strict_types=1);

namespace PhpSync\Sync;

/**
 * Metadata for a single file (from the fast listing phase).
 */
final class FileEntry
{
    public function __construct(
        public readonly string $path,   // relative path (raw bytes)
        public readonly int $size,
        public readonly int $mtime,
    ) {
    }
}
