<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

/**
 * One file to transfer, carrying both endpoints' original (per-side) paths.
 *
 * The two paths can differ when the same logical file is stored under different
 * Unicode normalization forms on each side (macOS NFD vs Linux NFC). The file is
 * READ from `sourcePath` and WRITTEN under `targetPath`, so a modified file
 * overwrites the existing entry on the target instead of creating a duplicate:
 *  - upload:   source = local path,  target = remote path
 *  - download: source = remote path, target = local path
 * For brand-new files both paths are identical.
 */
final class TransferItem
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $targetPath,
        public readonly int $size,
    ) {
    }
}
