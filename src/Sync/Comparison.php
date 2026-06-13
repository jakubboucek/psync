<?php

declare(strict_types=1);

namespace PhpSync\Sync;

/**
 * Result of a local↔remote comparison. Direction-neutral - upload/download
 * pick from the categories what to transfer and what to (optionally) delete.
 */
final class Comparison
{
    /**
     * @param array<string, FileEntry> $localOnly  local only (rel => entry)
     * @param array<string, FileEntry> $remoteOnly remote only
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $modified content differs
     * @param list<string> $equal identical
     * @param int $hashedCount how many files were actually hashed (diagnostics)
     */
    public function __construct(
        public readonly array $localOnly,
        public readonly array $remoteOnly,
        public readonly array $modified,
        public readonly array $equal,
        public readonly int $hashedCount = 0,
    ) {
    }

    public function isInSync(): bool
    {
        return $this->localOnly === [] && $this->remoteOnly === [] && $this->modified === [];
    }
}
