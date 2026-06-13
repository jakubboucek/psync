<?php

declare(strict_types=1);

namespace PhpSync\Protocol;

/**
 * Hlavička jednoho souboru v binárním framu (download/upload).
 */
final class FrameHeader
{
    public function __construct(
        public readonly string $path,      // relativní cesta (raw bajty, může být non-UTF8)
        public readonly int $flags,        // bitová maska, viz Protocol::FLAG_*
        public readonly int $mtime,        // mtime zdroje (unix)
        public readonly int $origSize,     // velikost původního (dekomprimovaného) obsahu
        public readonly int $payloadLen,   // délka payloadu na wire (po případné kompresi)
        public readonly string $md5,       // raw md5 (16 B) původního obsahu
    ) {
    }

    public function isGzipped(): bool
    {
        return ($this->flags & Protocol::FLAG_GZIP) !== 0;
    }

    public function md5Hex(): string
    {
        return bin2hex($this->md5);
    }
}
