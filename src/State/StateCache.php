<?php

declare(strict_types=1);

namespace PhpSync\State;

/**
 * Local cache of hashing results, so that files with identical content but
 * differing mtime are not rehashed on every comparison.
 *
 * Key = base64(rel). Value = {ls, lm, rm, md5, eq}:
 *   ls/lm = local size/mtime at the time of the check
 *   rm    = remote mtime at the time of the check
 *   md5   = the verified local md5
 *   eq    = was the content identical to remote?
 *
 * The verdict is reused only when ls, lm and rm all match - any change → rehash.
 */
final class StateCache
{
    /** @var array<string, array{ls:int, lm:int, rm:int, md5:string, eq:bool}> */
    private array $data = [];

    public function __construct(private readonly string $file)
    {
        $this->load();
    }

    /**
     * Returns the stored equality verdict if both the local and remote metadata match.
     */
    public function lookup(string $rel, int $localSize, int $localMtime, int $remoteMtime): ?bool
    {
        $row = $this->data[base64_encode($rel)] ?? null;
        if ($row === null) {
            return null;
        }
        if ($row['ls'] === $localSize && $row['lm'] === $localMtime && $row['rm'] === $remoteMtime) {
            return $row['eq'];
        }
        return null;
    }

    public function store(string $rel, int $localSize, int $localMtime, int $remoteMtime, string $md5, bool $equal): void
    {
        $this->data[base64_encode($rel)] = [
            'ls' => $localSize,
            'lm' => $localMtime,
            'rm' => $remoteMtime,
            'md5' => $md5,
            'eq' => $equal,
        ];
    }

    public function save(): void
    {
        @file_put_contents($this->file, json_encode($this->data, JSON_UNESCAPED_SLASHES));
    }

    private function load(): void
    {
        if (!is_file($this->file)) {
            return;
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            return;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            /** @var array<string, array{ls:int, lm:int, rm:int, md5:string, eq:bool}> $decoded */
            $this->data = $decoded;
        }
    }
}
