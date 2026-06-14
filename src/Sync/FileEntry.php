<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

/**
 * Metadata for a single file (from the fast listing phase).
 */
final readonly class FileEntry
{
    public function __construct(
        public string $path,   // relative path (raw bytes)
        public int $size,
        public int $mtime,
    ) {
    }
}
