<?php

declare(strict_types=1);

namespace PhpSync\Sync;

use PhpSync\Protocol\Protocol;
use PhpSync\Protocol\Wire;
use PhpSync\State\StateCache;
use PhpSync\Transport\HttpClient;

/**
 * Jádro 2-fázového porovnání:
 *  1) rychlý listing (size + mtime) na obou stranách
 *  2) pro size-shodné/mtime-rozdílné kandidáty md5 (lokálně + dávkovaně na serveru)
 *
 * State-cache umožňuje přeskočit hashování souborů, jejichž metadata se od
 * minulého ověření nezměnila. Režim --checksum cache i mtime ignoruje.
 */
final class Comparator
{
    /** Strop dávky pro server hash: součet velikostí (100 MB) a počet souborů. */
    private const HASH_BATCH_BYTES = 100 * 1024 * 1024;
    private const HASH_BATCH_FILES = 1000;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Walker $walker,
        private readonly IgnoreMatcher $ignore,
        private readonly StateCache $cache,
        private readonly string $localRoot,
        private readonly bool $checksum = false,
    ) {
    }

    public function compare(string $scope = ''): Comparison
    {
        $local = $this->localList($scope);
        $remote = $this->remoteList($scope);

        $localOnly = [];
        $remoteOnly = [];
        $modified = [];
        $equal = [];
        /** @var array<string, array{local: FileEntry, remote: FileEntry}> $candidates */
        $candidates = [];

        foreach ($local as $rel => $l) {
            $r = $remote[$rel] ?? null;
            if ($r === null) {
                $localOnly[$rel] = $l;
                continue;
            }
            if (!$this->checksum && $l->size !== $r->size) {
                $modified[$rel] = ['local' => $l, 'remote' => $r];
                continue;
            }
            if (!$this->checksum && $l->size === $r->size && $l->mtime === $r->mtime) {
                $equal[] = $rel;
                continue;
            }
            // size shodný + mtime rozdílný (nebo --checksum) → kandidát na hash
            $candidates[$rel] = ['local' => $l, 'remote' => $r];
        }
        foreach ($remote as $rel => $r) {
            if (!isset($local[$rel])) {
                $remoteOnly[$rel] = $r;
            }
        }

        $hashed = $this->resolveCandidates($candidates, $modified, $equal);
        $this->cache->save();

        ksort($localOnly);
        ksort($remoteOnly);
        ksort($modified);
        sort($equal);

        return new Comparison($localOnly, $remoteOnly, $modified, $equal, $hashed);
    }

    /**
     * Rozhodne kandidáty přes md5. Vrátí počet reálně přehashovaných souborů.
     *
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $candidates
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $modified  (ref – doplní se)
     * @param list<string> $equal (ref – doplní se)
     */
    private function resolveCandidates(array $candidates, array &$modified, array &$equal): int
    {
        if ($candidates === []) {
            return 0;
        }

        // 1) Zkus cache (mimo --checksum).
        $needHash = [];
        foreach ($candidates as $rel => $pair) {
            if (!$this->checksum) {
                $verdict = $this->cache->lookup($rel, $pair['local']->size, $pair['local']->mtime, $pair['remote']->mtime);
                if ($verdict !== null) {
                    $verdict ? $equal[] = $rel : $modified[$rel] = $pair;
                    continue;
                }
            }
            $needHash[$rel] = $pair;
        }
        if ($needHash === []) {
            return 0;
        }

        // 2) Remote md5 dávkově, lokální md5 lokálně.
        $remoteHashes = $this->remoteHashes($needHash);

        foreach ($needHash as $rel => $pair) {
            $localMd5 = @hash_file(Protocol::HASH_ALGO, $this->localRoot . '/' . $rel);
            $remoteMd5 = $remoteHashes[$rel] ?? null;
            $eq = ($localMd5 !== false && $remoteMd5 !== null && $localMd5 === $remoteMd5);

            if ($eq) {
                $equal[] = $rel;
            } else {
                $modified[$rel] = $pair;
            }
            if ($localMd5 !== false) {
                $this->cache->store($rel, $pair['local']->size, $pair['local']->mtime, $pair['remote']->mtime, $localMd5, $eq);
            }
        }
        return count($needHash);
    }

    /**
     * Spočítá remote md5 pro kandidáty, dávkováno dle limitu velikosti/počtu.
     *
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $candidates
     * @return array<string, string> rel => md5
     */
    private function remoteHashes(array $candidates): array
    {
        $result = [];
        $batch = [];
        $batchBytes = 0;

        $flush = function (array $batch) use (&$result): void {
            if ($batch === []) {
                return;
            }
            $paths = array_map(static fn(string $rel): string => Wire::encPath($rel), $batch);
            $this->http->streamJson(Protocol::ACTION_HASH, ['paths' => array_values($paths)], static function (array $o) use (&$result): void {
                if (isset($o['p']) && array_key_exists('h', $o) && $o['h'] !== null) {
                    $result[Wire::decPath($o['p'])] = (string) $o['h'];
                }
            });
        };

        foreach ($candidates as $rel => $pair) {
            $size = $pair['remote']->size;
            if ($batch !== [] && ($batchBytes + $size > self::HASH_BATCH_BYTES || count($batch) >= self::HASH_BATCH_FILES)) {
                $flush($batch);
                $batch = [];
                $batchBytes = 0;
            }
            $batch[] = $rel;
            $batchBytes += $size;
        }
        $flush($batch);

        return $result;
    }

    /**
     * @return array<string, FileEntry>
     */
    private function localList(string $scope): array
    {
        $map = [];
        foreach ($this->walker->walk($scope) as $entry) {
            $map[$entry->path] = $entry;
        }
        return $map;
    }

    /**
     * @return array<string, FileEntry>
     */
    private function remoteList(string $scope): array
    {
        $map = [];
        $this->http->streamJson(Protocol::ACTION_LIST, ['path' => $scope], function (array $o) use (&$map): void {
            if (!isset($o['p'])) {
                return; // {"end":true}
            }
            $rel = Wire::decPath($o['p']);
            if ($this->ignore->matches($rel)) {
                return;
            }
            $map[$rel] = new FileEntry($rel, (int) ($o['s'] ?? 0), (int) ($o['m'] ?? 0));
        });
        return $map;
    }
}
