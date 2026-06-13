<?php

declare(strict_types=1);

namespace PhpSync\Sync;

use PhpSync\Protocol\FrameWriter;
use PhpSync\Protocol\Wire;
use PhpSync\Transport\HttpClient;

/**
 * Nahrává soubory na server v binárních dávkách (frames). Velikost dávky je
 * omezena reálným post_max_size serveru (z capabilities) – tělo requestu se
 * přes něj nesmí dostat. Každá dávka je samostatný request → resumovatelné.
 */
final class Uploader
{
    /** Rezerva pod post_max_size na hlavičky apod. */
    private const MARGIN = 16 * 1024;

    /** Fallback, když server hlásí neomezené post_max_size (0). */
    private const DEFAULT_LIMIT = 64 * 1024 * 1024;

    private int $limit;

    /**
     * @param array<string, mixed> $caps
     * @param list<string> $skipExt
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $localRoot,
        array $caps,
        private readonly bool $compress,
        private readonly array $skipExt,
    ) {
        $post = (int) ($caps['postMaxSize'] ?? 0);
        $this->limit = $post > 0 ? max(self::MARGIN * 2, $post - self::MARGIN) : self::DEFAULT_LIMIT;
    }

    /**
     * @param array<string, FileEntry> $files rel => entry (lokální)
     * @param callable(string $rel, bool $ok, ?string $err): void $onResult
     */
    public function upload(array $files, callable $onResult): void
    {
        /** @var list<array{rel:string, tmp:string, size:int}> $batch */
        $batch = [];
        $batchBytes = 0;

        foreach ($files as $rel => $entry) {
            $abs = $this->localRoot . '/' . $rel;
            if (!is_file($abs)) {
                $onResult($rel, false, 'lokální soubor zmizel');
                continue;
            }
            $gz = $this->compress && !$this->skipped($rel);
            $frame = FrameWriter::buildFrame($rel, $abs, $gz);

            if ($frame['size'] > $this->limit) {
                @unlink($frame['tmp']);
                $onResult($rel, false, sprintf(
                    'soubor je větší než post_max_size serveru (%s > %s) – bulk upload ho nepřenese',
                    $this->human($frame['size']),
                    $this->human($this->limit),
                ));
                continue;
            }
            if ($batch !== [] && $batchBytes + $frame['size'] > $this->limit) {
                $this->flush($batch, $onResult);
                $batch = [];
                $batchBytes = 0;
            }
            $batch[] = ['rel' => $rel, 'tmp' => $frame['tmp'], 'size' => $frame['size']];
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
        // Tělo dávky je ≤ post_max_size (malé), složíme ho do stringu.
        $body = '';
        foreach ($batch as $item) {
            $body .= (string) file_get_contents($item['tmp']);
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
                $onResult($item['rel'], false, 'server nevrátil výsledek');
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
