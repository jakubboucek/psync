<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

/**
 * Filesystem entry type, as carried on the wire (the `t` key of the NDJSON listing
 * and of the delete request).
 *
 * The backing value is a one-letter code so the wire stays compact and
 * forward-compatible: new kinds (e.g. a symlink) get their own letter without
 * breaking the format. By convention a regular file omits the `t` key entirely
 * (absence == File), so the common listing line keeps its historical {p,s,m} shape.
 */
enum FileType: string
{
    case File = 'f';
    case Dir = 'd';

    /**
     * Maps a wire code to the type. A missing/empty code means a regular file
     * (the lazy default). Returns null for an unknown code so the caller can
     * skip-and-warn instead of mis-handling a future type.
     */
    public static function fromWire(?string $code): ?self
    {
        if ($code === null || $code === '') {
            return self::File;
        }
        return self::tryFrom($code);
    }

    /** Wire code, or null when it should be omitted (regular file = lazy default). */
    public function toWire(): ?string
    {
        return $this === self::File ? null : $this->value;
    }
}
