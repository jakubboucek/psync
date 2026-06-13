<?php

declare(strict_types=1);

namespace PhpSync\Sync;

/**
 * Metadata jednoho souboru (z rychlé fáze listingu).
 */
final class FileEntry
{
    public function __construct(
        public readonly string $path,   // relativní cesta (raw bajty)
        public readonly int $size,
        public readonly int $mtime,
    ) {
    }
}
