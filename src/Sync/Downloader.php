<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

use JakubBoucek\Psync\Protocol\FrameHeader;
use JakubBoucek\Psync\Protocol\Wire;
use JakubBoucek\Psync\Transport\HttpClient;
use RuntimeException;

/**
 * Downloads files from the server in binary batches. Each batch is a separate
 * request; received frames are written atomically (tmp + rename) with the source
 * mtime applied. An unfinished batch (crash) is re-requested on the next run - resumable.
 */
final readonly class Downloader
{
    /** Batch cap by total size (so the request stays within the server's limit). */
    private const BATCH_BYTES = 64 * 1024 * 1024;
    private const int BATCH_FILES = 1000;
    private const CHUNK = 1 << 16;

    /**
     * @param list<string> $skipExt
     */
    public function __construct(
        private HttpClient $http,
        private string $localRoot,
        private bool $compress,
        private array $skipExt,
    ) {
    }

    /**
     * @param list<TransferItem> $items  requested from sourcePath (remote), written under targetPath (local)
     * @param callable(string $rel, bool $ok, ?string $err): void $onResult
     */
    public function download(array $items, callable $onResult): void
    {
        /** @var list<TransferItem> $batch */
        $batch = [];
        $batchBytes = 0;

        foreach ($items as $item) {
            if ($batch !== [] && ($batchBytes + $item->size > self::BATCH_BYTES || count($batch) >= self::BATCH_FILES)) {
                $this->fetchBatch($batch, $onResult);
                $batch = [];
                $batchBytes = 0;
            }
            $batch[] = $item;
            $batchBytes += $item->size;
        }
        $this->fetchBatch($batch, $onResult);
    }

    /**
     * @param list<TransferItem> $items
     * @param callable(string, bool, ?string): void $onResult
     */
    private function fetchBatch(array $items, callable $onResult): void
    {
        if ($items === []) {
            return;
        }
        // The agent frames each file under the path it was requested with (the
        // remote/source bytes); map that back to the local target to write under.
        $targetBySource = [];
        $paths = [];
        foreach ($items as $item) {
            $targetBySource[$item->sourcePath] = $item->targetPath;
            $paths[] = Wire::encPath($item->sourcePath);
        }
        $payload = [
            'files' => $paths,
            'compress' => $this->compress,
            'skipExt' => $this->skipExt,
        ];
        $tmp = $this->http->downloadToTemp($payload);

        $received = [];
        try {
            $in = fopen($tmp, 'rb');
            if ($in === false) {
                throw new RuntimeException('Cannot open the downloaded data.');
            }
            while (($header = Wire::readFrameHeader($in)) !== null) {
                $source = $header->path;
                $target = $targetBySource[$source] ?? $source;
                $err = $this->writeFrame($in, $header, $target);
                $received[$source] = true;
                $onResult($target, $err === null, $err);
            }
            fclose($in);
        } finally {
            @unlink($tmp);
        }

        // Files the server did not return (skipped - missing/unreadable).
        foreach ($items as $item) {
            if (!isset($received[$item->sourcePath])) {
                $onResult($item->targetPath, false, 'server did not return the file');
            }
        }
    }

    /**
     * Writes a single frame atomically under $targetRel. Returns null on success, otherwise an error.
     *
     * @param resource $in
     */
    private function writeFrame($in, FrameHeader $header, string $targetRel): ?string
    {
        $target = $this->localRoot . '/' . $targetRel;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->discard($in, $header->payloadLen);
            return 'cannot create directory';
        }

        $tmp = $target . '.psync.tmp';
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            $this->discard($in, $header->payloadLen);
            return 'cannot open target temp file';
        }

        $ctx = hash_init('md5');
        $inflate = null;
        if ($header->isGzipped()) {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflate === false) {
                fclose($out);
                @unlink($tmp);
                $this->discard($in, $header->payloadLen);
                return 'cannot initialize decompression';
            }
        }
        $remaining = $header->payloadLen;

        while ($remaining > 0) {
            $chunk = fread($in, max(1, min(self::CHUNK, $remaining)));
            if ($chunk === false || $chunk === '') {
                fclose($out);
                @unlink($tmp);
                return 'truncated payload';
            }
            $remaining -= strlen($chunk);
            if ($inflate !== null) {
                $chunk = inflate_add($inflate, $chunk);
                if ($chunk === false) {
                    fclose($out);
                    @unlink($tmp);
                    return 'decompression error';
                }
            }
            if ($chunk !== '') {
                hash_update($ctx, $chunk);
                fwrite($out, $chunk);
            }
        }
        if ($inflate !== null) {
            $tail = inflate_add($inflate, '', ZLIB_FINISH);
            if ($tail === false) {
                fclose($out);
                @unlink($tmp);
                return 'decompression error (finish)';
            }
            if ($tail !== '') {
                hash_update($ctx, $tail);
                fwrite($out, $tail);
            }
        }
        fclose($out);

        if (hash_final($ctx, true) !== $header->md5) {
            @unlink($tmp);
            return 'md5 mismatch';
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return 'rename failed';
        }
        @touch($target, $header->mtime);
        return null;
    }

    /**
     * @param resource $in
     */
    private function discard($in, int $n): void
    {
        while ($n > 0) {
            $chunk = fread($in, max(1, min(self::CHUNK, $n)));
            if ($chunk === false || $chunk === '') {
                return;
            }
            $n -= strlen($chunk);
        }
    }
}
