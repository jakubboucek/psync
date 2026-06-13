<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Sync;

use JakubBoucek\Psync\Console\Reporter;
use JakubBoucek\Psync\Protocol\Protocol;
use JakubBoucek\Psync\Protocol\Wire;
use JakubBoucek\Psync\State\StateCache;
use JakubBoucek\Psync\Transport\HttpClient;

/**
 * Core of the 2-phase comparison:
 *  1) fast listing (size + mtime) on both sides
 *  2) md5 for size-identical/mtime-differing candidates (locally + batched on the server)
 *
 * The state cache allows skipping the hashing of files whose metadata has not
 * changed since the last check. The --checksum mode ignores both the cache and mtime.
 */
final class Comparator
{
    /** Batch cap for server hashing: total size (100 MB) and file count. */
    private const HASH_BATCH_BYTES = 100 * 1024 * 1024;
    private const HASH_BATCH_FILES = 1000;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Walker $walker,
        private readonly IgnoreMatcher $ignore,
        private readonly StateCache $cache,
        private readonly string $localRoot,
        private readonly bool $checksum = false,
        private readonly ?Reporter $reporter = null,
    ) {
    }

    public function compare(string $scope = ''): Comparison
    {
        $local = $this->localList($scope);
        $remote = $this->remoteList($scope);
        $this->reporter?->log(sprintf('Local: %d files, remote: %d files', count($local), count($remote)));

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
            // identical size + differing mtime (or --checksum) → hash candidate
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
     * Resolves candidates via md5. Returns the number of files actually rehashed.
     *
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $candidates
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $modified  (ref - appended to)
     * @param list<string> $equal (ref - appended to)
     */
    private function resolveCandidates(array $candidates, array &$modified, array &$equal): int
    {
        if ($candidates === []) {
            return 0;
        }

        // 1) Try the cache (except in --checksum mode).
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
        $this->reporter?->log(sprintf(
            'Hash candidates: %d (%d reused from cache, %d to hash)',
            count($candidates),
            count($candidates) - count($needHash),
            count($needHash),
        ));
        if ($needHash === []) {
            return 0;
        }

        // 2) Remote md5 in batches, local md5 locally.
        $remoteHashes = $this->remoteHashes($needHash);

        foreach ($needHash as $rel => $pair) {
            $localMd5 = @hash_file(Protocol::HASH_ALGO, $this->localRoot . '/' . $rel);
            $remoteMd5 = $remoteHashes[$rel] ?? null;
            $eq = ($localMd5 !== false && $remoteMd5 !== null && $localMd5 === $remoteMd5);

            $this->reporter?->debug(sprintf(
                '%s %s (local %s / remote %s)',
                $eq ? '=' : '≠',
                $rel,
                substr($localMd5 === false ? '?' : $localMd5, 0, 8),
                substr($remoteMd5 ?? '?', 0, 8),
            ));

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
     * Computes remote md5 for candidates, batched by the size/count limit.
     *
     * @param array<string, array{local: FileEntry, remote: FileEntry}> $candidates
     * @return array<string, string> rel => md5
     */
    private function remoteHashes(array $candidates): array
    {
        $result = [];
        $batch = [];
        $batchBytes = 0;
        $total = count($candidates);
        $done = 0;
        $this->reporter?->progressStart('Hashing:');

        $flush = function (array $batch) use (&$result, &$done, $total): void {
            if ($batch === []) {
                return;
            }
            $paths = array_map(static fn(string $rel): string => Wire::encPath($rel), $batch);
            $this->http->streamJson(Protocol::ACTION_HASH, ['paths' => array_values($paths)], function (array $o) use (&$result, &$done, $total): void {
                if (isset($o['p']) && array_key_exists('h', $o) && $o['h'] !== null) {
                    $result[Wire::decPath($o['p'])] = (string) $o['h'];
                    $this->reporter?->progressUpdate(++$done, $total);
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
        $this->reporter?->progressDone();

        return $result;
    }

    /**
     * @return array<string, FileEntry>
     */
    private function localList(string $scope): array
    {
        $map = [];
        $this->reporter?->progressStart('Scanning local:');
        $n = 0;
        foreach ($this->walker->walk($scope) as $entry) {
            $map[$entry->path] = $entry;
            $this->reporter?->progressUpdate(++$n);
        }
        $this->reporter?->progressDone();
        return $map;
    }

    /**
     * @return array<string, FileEntry>
     */
    private function remoteList(string $scope): array
    {
        $map = [];
        $this->reporter?->progressStart('Scanning remote:');
        $n = 0;
        $this->http->streamJson(Protocol::ACTION_LIST, ['path' => $scope], function (array $o) use (&$map, &$n): void {
            if (!isset($o['p'])) {
                return; // {"end":true}
            }
            $rel = Wire::decPath($o['p']);
            $this->reporter?->progressUpdate(++$n);
            if ($this->ignore->matches($rel)) {
                return;
            }
            $map[$rel] = new FileEntry($rel, (int) ($o['s'] ?? 0), (int) ($o['m'] ?? 0));
        });
        $this->reporter?->progressDone();
        return $map;
    }
}
