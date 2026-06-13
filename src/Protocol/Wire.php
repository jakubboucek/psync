<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Protocol;

use JsonException;
use RuntimeException;

/**
 * On-the-wire serialization.
 *
 * Two formats:
 *  - NDJSON (the list/hash/control channels). Paths are base64, because file names
 *    on legacy servers are often non-UTF8 (Windows-1250) and `json_encode` would fail.
 *  - Binary framing (download/upload). Length-prefixed, streamable, memory-light.
 *    Layout of a single frame:
 *
 *      [u32 pathLen][path bytes][u8 flags][u64 mtime][u64 origSize][u64 payloadLen][16 md5]
 *      [payloadLen bytes of payload]
 *
 *    Everything big-endian (pack 'N'/'C'/'J'). Fixed header part after the path = 41 B.
 *    The agent (PHP 7.4) must produce a byte-identical format.
 */
final class Wire
{
    /** Length of the fixed header part after the path: flags(1)+mtime(8)+origSize(8)+payloadLen(8)+md5(16). */
    private const HEADER_FIXED = 41;

    // --- NDJSON -----------------------------------------------------------

    /** @param array<string, mixed> $obj */
    public static function ndjson(array $obj): string
    {
        try {
            return json_encode($obj, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException('NDJSON encode failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    public static function parseNdjson(string $line): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            return $data;
        } catch (JsonException $e) {
            throw new RuntimeException('NDJSON decode failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function encPath(string $path): string
    {
        return base64_encode($path);
    }

    public static function decPath(string $b64): string
    {
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            throw new RuntimeException('Invalid base64 in path.');
        }
        return $raw;
    }

    // --- Binary framing ---------------------------------------------------

    public static function packFrameHeader(FrameHeader $h): string
    {
        if (strlen($h->md5) !== 16) {
            throw new RuntimeException('md5 must be 16 raw bytes.');
        }
        return pack('N', strlen($h->path)) . $h->path
            . pack('C', $h->flags)
            . pack('J', $h->mtime)
            . pack('J', $h->origSize)
            . pack('J', $h->payloadLen)
            . $h->md5;
    }

    /**
     * Reads the header of a single frame from the stream. Returns null on clean EOF.
     * The payload (payloadLen bytes) stays in the stream – the caller reads/copies it.
     *
     * @param resource $stream
     */
    public static function readFrameHeader($stream): ?FrameHeader
    {
        $lenRaw = self::tryReadExact($stream, 4);
        if ($lenRaw === null) {
            return null; // end of stream
        }
        $pathLen = self::unpackInt('N', $lenRaw);
        $path = $pathLen > 0 ? self::readExact($stream, $pathLen) : '';
        $fixed = self::readExact($stream, self::HEADER_FIXED);

        return new FrameHeader(
            $path,
            self::unpackInt('C', substr($fixed, 0, 1)),
            self::unpackInt('J', substr($fixed, 1, 8)),
            self::unpackInt('J', substr($fixed, 9, 8)),
            self::unpackInt('J', substr($fixed, 17, 8)),
            substr($fixed, 25, 16),
        );
    }

    /**
     * Reads exactly $n bytes from the stream (fread may return fewer). Throws an
     * exception on truncation (including at EOF). To detect EOF on a frame boundary
     * see tryReadExact().
     *
     * @param resource $stream
     */
    public static function readExact($stream, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($stream, max(1, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException(sprintf(
                    'Truncated stream: expected %d B, read %d B.',
                    $n,
                    strlen($buf),
                ));
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Like readExact, but returns null on clean EOF (no byte arrived).
     *
     * @param resource $stream
     */
    public static function tryReadExact($stream, int $n): ?string
    {
        $first = fread($stream, max(1, $n));
        if ($first === false || $first === '') {
            return null;
        }
        if (strlen($first) >= $n) {
            return $first;
        }
        return $first . self::readExact($stream, $n - strlen($first));
    }

    /**
     * Copies exactly $n bytes from the source stream to the destination, chunk by chunk.
     *
     * @param resource $in
     * @param resource $out
     */
    public static function copyExact($in, $out, int $n, int $chunkSize = 1 << 16): void
    {
        $remaining = $n;
        while ($remaining > 0) {
            $chunk = fread($in, max(1, min($chunkSize, $remaining)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException("Truncated payload: $remaining B remaining.");
            }
            if (fwrite($out, $chunk) === false) {
                throw new RuntimeException('Write to destination stream failed.');
            }
            $remaining -= strlen($chunk);
        }
    }

    private static function unpackInt(string $format, string $data): int
    {
        $unpacked = unpack($format, $data);
        if ($unpacked === false) {
            throw new RuntimeException('Unpacking the binary header failed.');
        }
        return (int) $unpacked[1];
    }
}
