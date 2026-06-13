<?php

declare(strict_types=1);

namespace PhpSync\State;

/**
 * Lokální cache výsledků hashování, aby se soubory se shodným obsahem, ale
 * rozdílným mtime nehashovaly při každém porovnání znovu.
 *
 * Klíč = base64(rel). Hodnota = {ls, lm, rm, md5, eq}:
 *   ls/lm = lokální size/mtime v době ověření
 *   rm    = remote mtime v době ověření
 *   md5   = ověřený lokální md5
 *   eq    = byl obsah shodný s remote?
 *
 * Verdikt se reusne jen když sedí ls, lm i rm – jakákoli změna → přehashovat.
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
     * Vrátí uložený verdikt rovnosti, pokud sedí lokální i remote metadata.
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
