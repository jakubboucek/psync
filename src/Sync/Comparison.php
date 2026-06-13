<?php

declare(strict_types=1);

namespace PhpSync\Sync;

/**
 * Výsledek porovnání local↔remote. Neutrální vůči směru – upload/download
 * si z kategorií vyberou, co přenést a co (volitelně) smazat.
 */
final class Comparison
{
    /**
     * @param array<string, FileEntry> $localOnly  jen lokálně (rel => entry)
     * @param array<string, FileEntry> $remoteOnly jen na serveru
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $modified liší se obsahem
     * @param list<string> $equal shodné
     * @param int $hashedCount kolik souborů se reálně hashovalo (diagnostika)
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
