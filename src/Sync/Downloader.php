<?php

declare(strict_types=1);

namespace PhpSync\Sync;

use PhpSync\Protocol\FrameHeader;
use PhpSync\Protocol\Wire;
use PhpSync\Transport\HttpClient;
use RuntimeException;

/**
 * Stahuje soubory ze serveru v binárních dávkách. Každá dávka je samostatný
 * request; přijaté framy se zapisují atomicky (tmp + rename) s nastavením mtime
 * zdroje. Nedokončená dávka (pád) se při dalším běhu doptá – resumovatelné.
 */
final class Downloader
{
    /** Strop dávky dle součtu velikostí (aby request stihl server v limitu). */
    private const BATCH_BYTES = 64 * 1024 * 1024;
    private const BATCH_FILES = 1000;
    private const CHUNK = 1 << 16;

    /**
     * @param list<string> $skipExt
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $localRoot,
        private readonly bool $compress,
        private readonly array $skipExt,
    ) {
    }

    /**
     * @param array<string, FileEntry> $files rel => entry (remote)
     * @param callable(string $rel, bool $ok, ?string $err): void $onResult
     */
    public function download(array $files, callable $onResult): void
    {
        $batch = [];
        $batchBytes = 0;

        foreach ($files as $rel => $entry) {
            if ($batch !== [] && ($batchBytes + $entry->size > self::BATCH_BYTES || count($batch) >= self::BATCH_FILES)) {
                $this->fetchBatch($batch, $onResult);
                $batch = [];
                $batchBytes = 0;
            }
            $batch[] = $rel;
            $batchBytes += $entry->size;
        }
        $this->fetchBatch($batch, $onResult);
    }

    /**
     * @param list<string> $rels
     * @param callable(string, bool, ?string): void $onResult
     */
    private function fetchBatch(array $rels, callable $onResult): void
    {
        if ($rels === []) {
            return;
        }
        $payload = [
            'files' => array_map(static fn(string $r): string => Wire::encPath($r), $rels),
            'compress' => $this->compress,
            'skipExt' => $this->skipExt,
        ];
        $tmp = $this->http->downloadToTemp($payload);

        $received = [];
        try {
            $in = fopen($tmp, 'rb');
            if ($in === false) {
                throw new RuntimeException('Nelze otevřít stažená data.');
            }
            while (($header = Wire::readFrameHeader($in)) !== null) {
                $rel = $header->path;
                $err = $this->writeFrame($in, $header);
                $received[$rel] = true;
                $onResult($rel, $err === null, $err);
            }
            fclose($in);
        } finally {
            @unlink($tmp);
        }

        // Soubory, které server nevrátil (přeskočil – chyběly/nečitelné).
        foreach ($rels as $rel) {
            if (!isset($received[$rel])) {
                $onResult($rel, false, 'server soubor nevrátil');
            }
        }
    }

    /**
     * Zapíše jeden frame atomicky. Vrátí null při úspěchu, jinak chybu.
     *
     * @param resource $in
     */
    private function writeFrame($in, FrameHeader $header): ?string
    {
        $target = $this->localRoot . '/' . $header->path;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->discard($in, $header->payloadLen);
            return 'nelze vytvořit adresář';
        }

        $tmp = $target . '.phpsync.tmp';
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            $this->discard($in, $header->payloadLen);
            return 'nelze otevřít cílový temp';
        }

        $ctx = hash_init('md5');
        $inflate = null;
        if ($header->isGzipped()) {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflate === false) {
                fclose($out);
                @unlink($tmp);
                $this->discard($in, $header->payloadLen);
                return 'nelze inicializovat dekompresi';
            }
        }
        $remaining = $header->payloadLen;

        while ($remaining > 0) {
            $chunk = fread($in, max(1, min(self::CHUNK, $remaining)));
            if ($chunk === false || $chunk === '') {
                fclose($out);
                @unlink($tmp);
                return 'useknutý payload';
            }
            $remaining -= strlen($chunk);
            if ($inflate !== null) {
                $chunk = inflate_add($inflate, $chunk);
                if ($chunk === false) {
                    fclose($out);
                    @unlink($tmp);
                    return 'chyba dekomprese';
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
                return 'chyba dekomprese (finish)';
            }
            if ($tail !== '') {
                hash_update($ctx, $tail);
                fwrite($out, $tail);
            }
        }
        fclose($out);

        if (hash_final($ctx, true) !== $header->md5) {
            @unlink($tmp);
            return 'md5 nesouhlasí';
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return 'rename selhal';
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
