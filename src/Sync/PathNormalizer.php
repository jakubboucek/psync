<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

use Normalizer;

/**
 * Produces a stable comparison key for a path regardless of Unicode
 * normalization form. macOS (APFS/HFS+) tends to return file names in NFD
 * (decomposed, e.g. "í" = i + combining acute), while Linux servers store them
 * in NFC (composed, e.g. precomposed "í"). Without folding, the same logical
 * file would appear as both local-only and server-only.
 *
 * The key is for MATCHING/DISPLAY only — the original bytes are kept on each
 * side for the actual file I/O (local filesystem and agent requests).
 *
 * Requires ext-intl; without it (or for non-UTF8 names like Windows-1250) the
 * original bytes are returned unchanged (byte comparison, the previous behavior).
 */
final class PathNormalizer
{
    private static ?bool $available = null;

    public static function key(string $path): string
    {
        self::$available ??= function_exists('normalizer_normalize');
        if (!self::$available) {
            return $path;
        }
        $normalized = @Normalizer::normalize($path, Normalizer::FORM_C);
        return $normalized === false ? $path : $normalized;
    }

    public static function isAvailable(): bool
    {
        return self::$available ??= function_exists('normalizer_normalize');
    }
}
