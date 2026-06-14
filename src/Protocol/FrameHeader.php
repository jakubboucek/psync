<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Protocol;

/**
 * Header of a single file in a binary frame (download/upload).
 */
final readonly class FrameHeader
{
    public function __construct(
        public string $path,      // relative path (raw bytes, may be non-UTF8)
        public int $flags,        // bit mask, see Protocol::FLAG_*
        public int $mtime,        // source mtime (unix)
        public int $origSize,     // size of the original (decompressed) content
        public int $payloadLen,   // payload length on the wire (after optional compression)
        public string $md5,       // raw md5 (16 B) of the original content
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
