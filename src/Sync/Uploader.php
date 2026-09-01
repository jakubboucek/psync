<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

use JakubBoucek\Psync\Protocol\FrameWriter;
use JakubBoucek\Psync\Protocol\Wire;
use JakubBoucek\Psync\Transport\HttpClient;

/**
 * Uploads files to the server in binary batches (frames). The batch size is
 * limited by the server's actual post_max_size (from capabilities) - the request
 * body must not exceed it. Each batch is a separate request → resumable.
 */
final readonly class Uploader
{
    /** Reserve below post_max_size for headers etc. */
    private const int MARGIN = 16 * 1024;

    /** Fallback when the server reports an unlimited post_max_size (0). */
    private const int DEFAULT_LIMIT = 64 * 1024 * 1024;

    /**
     * Client-side batch cap: flush() assembles the batch body into a string
     * (and curl keeps another copy), so the batch must stay well below the
     * client's memory_limit no matter how big a post_max_size the server
     * reports. Caps only batching - a single file may still use the full
     * server limit (it just travels as a solo batch).
     */
    private const int BATCH_MEMORY_CAP = 32 * 1024 * 1024;

    /** Per-file maximum = the server's post_max_size (minus margin). */
    private int $limit;

    /** Flush threshold: min(server limit, client memory cap). */
    private int $batchLimit;

    /**
     * @param array<string, mixed> $caps
     * @param list<string> $skipExt
     */
    public function __construct(
        private HttpClient $http,
        private string $localRoot,
        array $caps,
        private bool $compress,
        private array $skipExt,
    ) {
        $post = (int) ($caps['postMaxSize'] ?? 0);
        $this->limit = $post > 0 ? max(self::MARGIN * 2, $post - self::MARGIN) : self::DEFAULT_LIMIT;
        $this->batchLimit = min($this->limit, self::BATCH_MEMORY_CAP);
    }

    /**
     * @param list<TransferItem> $items  read from sourcePath (local), written under targetPath (remote)
     * @param callable(string $rel, bool $ok, ?string $err): void $onResult
     */
    public function upload(array $items, callable $onResult): void
    {
        /** @var list<array{rel:string, tmp:string, size:int}> $batch */
        $batch = [];
        $batchBytes = 0;

        foreach ($items as $item) {
            $abs = $this->localRoot . '/' . $item->sourcePath;
            if (!is_file($abs)) {
                $onResult($item->targetPath, false, 'local file disappeared');
                continue;
            }
            $gz = $this->compress && !$this->skipped($item->sourcePath);
            // Frame path = the TARGET (remote) name, so a modified file overwrites
            // the existing remote entry instead of creating a normalization duplicate.
            $frame = FrameWriter::buildFrame($item->targetPath, $abs, $gz);

            if ($frame['size'] > $this->limit) {
                @unlink($frame['tmp']);
                $onResult($item->targetPath, false, sprintf(
                    'file is larger than the server post_max_size (%s > %s) - bulk upload cannot transfer it',
                    $this->human($frame['size']),
                    $this->human($this->limit),
                ));
                continue;
            }
            if ($batch !== [] && $batchBytes + $frame['size'] > $this->batchLimit) {
                $this->flush($batch, $onResult);
                $batch = [];
                $batchBytes = 0;
            }
            $batch[] = ['rel' => $item->targetPath, 'tmp' => $frame['tmp'], 'size' => $frame['size']];
            $batchBytes += $frame['size'];
        }
        $this->flush($batch, $onResult);
    }

    /**
     * @param list<array{rel:string, tmp:string, size:int}> $batch
     * @param callable(string, bool, ?string): void $onResult
     */
    private function flush(array $batch, callable $onResult): void
    {
        if ($batch === []) {
            return;
        }
        // The batch body is capped by batchLimit (except a solo oversized file),
        // so assembling it into a string is safe for the client memory_limit.
        $body = '';
        foreach ($batch as $item) {
            $body .= file_get_contents($item['tmp']);
            @unlink($item['tmp']);
        }

        $results = [];
        $this->http->uploadBody($body, static function (array $o) use (&$results): void {
            if (isset($o['p'])) {
                $results[Wire::decPath($o['p'])] = $o;
            }
        });

        foreach ($batch as $item) {
            $r = $results[$item['rel']] ?? null;
            if ($r === null) {
                $onResult($item['rel'], false, 'server returned no result');
            } else {
                $onResult($item['rel'], (bool) ($r['ok'] ?? false), isset($r['err']) ? (string) $r['err'] : null);
            }
        }
    }

    private function skipped(string $rel): bool
    {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        return in_array($ext, $this->skipExt, true);
    }

    private function human(int $n): string
    {
        $u = ['B', 'kB', 'MB', 'GB'];
        $i = 0;
        $v = (float) $n;
        while ($v >= 1024 && $i < 3) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $n : sprintf('%.1f', $v)) . ' ' . $u[$i];
    }
}
