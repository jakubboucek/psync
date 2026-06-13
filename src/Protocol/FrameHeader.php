<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Protocol;

/**
 * Header of a single file in a binary frame (download/upload).
 */
final class FrameHeader
{
    public function __construct(
        public readonly string $path,      // relative path (raw bytes, may be non-UTF8)
        public readonly int $flags,        // bit mask, see Protocol::FLAG_*
        public readonly int $mtime,        // source mtime (unix)
        public readonly int $origSize,     // size of the original (decompressed) content
        public readonly int $payloadLen,   // payload length on the wire (after optional compression)
        public readonly string $md5,       // raw md5 (16 B) of the original content
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
