<?php

declare(strict_types=1);

namespace PhpSync\Protocol;

use JsonException;
use RuntimeException;

/**
 * Serializace na drátě.
 *
 * Dva formáty:
 *  - NDJSON (řídicí kanály list/hash/control). Cesty jsou base64, protože názvy
 *    na legacy serverech bývají non-UTF8 (Windows-1250) a `json_encode` by selhal.
 *  - Binární framing (download/upload). Length-prefixed, streamovatelný, paměťově
 *    nenáročný. Layout jednoho framu:
 *
 *      [u32 pathLen][path bajty][u8 flags][u64 mtime][u64 origSize][u64 payloadLen][16 md5]
 *      [payloadLen bajtů payloadu]
 *
 *    Vše big-endian (pack 'N'/'C'/'J'). Fixní část hlavičky za cestou = 41 B.
 *    Agent (PHP 7.4) musí produkovat bajt-identický formát.
 */
final class Wire
{
    /** Délka fixní části hlavičky za cestou: flags(1)+mtime(8)+origSize(8)+payloadLen(8)+md5(16). */
    private const HEADER_FIXED = 41;

    // --- NDJSON -----------------------------------------------------------

    /** @param array<string, mixed> $obj */
    public static function ndjson(array $obj): string
    {
        try {
            return json_encode($obj, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException('NDJSON encode selhal: ' . $e->getMessage(), 0, $e);
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
            throw new RuntimeException('NDJSON decode selhal: ' . $e->getMessage(), 0, $e);
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
            throw new RuntimeException('Neplatné base64 v cestě.');
        }
        return $raw;
    }

    // --- Binární framing --------------------------------------------------

    public static function packFrameHeader(FrameHeader $h): string
    {
        if (strlen($h->md5) !== 16) {
            throw new RuntimeException('md5 musí být 16 raw bajtů.');
        }
        return pack('N', strlen($h->path)) . $h->path
            . pack('C', $h->flags)
            . pack('J', $h->mtime)
            . pack('J', $h->origSize)
            . pack('J', $h->payloadLen)
            . $h->md5;
    }

    /**
     * Přečte hlavičku jednoho framu ze streamu. Vrátí null na čistém EOF.
     * Payload (payloadLen bajtů) zůstává ve streamu – přečte/zkopíruje ho volající.
     *
     * @param resource $stream
     */
    public static function readFrameHeader($stream): ?FrameHeader
    {
        $lenRaw = self::tryReadExact($stream, 4);
        if ($lenRaw === null) {
            return null; // konec streamu
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
     * Přečte přesně $n bajtů ze streamu (fread může vrátit méně). Vyhodí výjimku
     * při useknutí (i na EOF). Pro detekci EOF na hranici framu viz tryReadExact().
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
                    'Useknutý stream: očekáváno %d B, přečteno %d B.',
                    $n,
                    strlen($buf),
                ));
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Jako readExact, ale na čistém EOF (žádný bajt nepřišel) vrátí null.
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
     * Zkopíruje přesně $n bajtů ze zdrojového streamu do cílového po chuncích.
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
                throw new RuntimeException("Useknutý payload: zbývalo $remaining B.");
            }
            if (fwrite($out, $chunk) === false) {
                throw new RuntimeException('Zápis do cílového streamu selhal.');
            }
            $remaining -= strlen($chunk);
        }
    }

    private static function unpackInt(string $format, string $data): int
    {
        $unpacked = unpack($format, $data);
        if ($unpacked === false) {
            throw new RuntimeException('Rozbalení binární hlavičky selhalo.');
        }
        return (int) $unpacked[1];
    }
}
