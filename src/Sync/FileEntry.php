<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

/**
 * Metadata for a single filesystem entry (from the fast listing phase).
 *
 * Directories are listed as first-class entries too (size 0, presence-only - no
 * mtime/hash comparison); $type distinguishes them from regular files.
 */
final readonly class FileEntry
{
    public function __construct(
        public string $path,   // relative path (raw bytes)
        public int $size,
        public int $mtime,
        public FileType $type = FileType::File,
    ) {
    }

    public function isDir(): bool
    {
        return $this->type === FileType::Dir;
    }
}
