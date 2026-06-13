<?php

declare(strict_types=1);

namespace PhpSync\Config;

use RuntimeException;

/**
 * Klientská konfigurace projektu, načtená z PHP souboru vracejícího pole.
 *
 * Bohatý config (mapování local↔remote, ignore, protect) žije na klientovi;
 * server agent zná jen svůj veřejný klíč, remote root a protect-list.
 */
final class Config
{
    /** @var list<string> */
    public readonly array $ignore;

    /** @var list<string> */
    public readonly array $protect;

    /** @var list<string> */
    public readonly array $compressSkipExt;

    public function __construct(
        public readonly string $url,
        public readonly ?string $privateKey,
        public readonly string $localRoot,
        public readonly string $remoteRoot,
        array $ignore = [],
        array $protect = [],
        public readonly bool $checksum = false,
        public readonly bool $compress = true,
        array $compressSkipExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'gz', 'pdf', 'mp4', 'mp3'],
        public readonly bool $serverGzipWorkaround = false,
    ) {
        $this->ignore = array_values(array_map('strval', $ignore));
        $this->protect = array_values(array_map('strval', $protect));
        $this->compressSkipExt = array_values(array_map(
            static fn($e): string => strtolower(ltrim((string) $e, '.')),
            $compressSkipExt,
        ));
    }

    /**
     * Načte a zvaliduje config z PHP souboru (`return [...]`).
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Konfigurační soubor neexistuje: $path");
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException("Konfigurační soubor musí vracet pole (`return [...]`): $path");
        }

        $require = static function (string $key) use ($data, $path): mixed {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                throw new RuntimeException("V configu chybí povinný klíč '$key': $path");
            }
            return $data[$key];
        };

        $mapping = $data['mapping'] ?? [];
        if (!is_array($mapping) || !isset($mapping['local'])) {
            throw new RuntimeException("Klíč 'mapping.local' je povinný: $path");
        }

        $local = self::normalizeDir((string) $mapping['local']);
        if (!is_dir($local)) {
            throw new RuntimeException("mapping.local neexistuje nebo není adresář: $local");
        }

        return new self(
            url: (string) $require('url'),
            privateKey: isset($data['privateKey']) ? (string) $data['privateKey'] : null,
            localRoot: $local,
            remoteRoot: self::normalizeRemote((string) ($mapping['remote'] ?? '/')),
            ignore: (array) ($data['ignore'] ?? []),
            protect: (array) ($data['protect'] ?? []),
            checksum: (bool) ($data['checksum'] ?? false),
            compress: (bool) ($data['compress'] ?? true),
            compressSkipExt: (array) ($data['compressSkipExt']
                ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'gz', 'pdf', 'mp4', 'mp3']),
            serverGzipWorkaround: (bool) ($data['serverGzipWorkaround'] ?? false),
        );
    }

    /**
     * Privátní klíč je nutný pro každou operaci kromě `install`.
     */
    public function requirePrivateKey(): string
    {
        if ($this->privateKey === null) {
            throw new RuntimeException(
                "V configu chybí 'privateKey'. Vygeneruj ho příkazem `install` a vlož do configu.",
            );
        }
        return $this->privateKey;
    }

    private static function normalizeDir(string $dir): string
    {
        $real = realpath($dir);
        return $real !== false ? $real : rtrim($dir, '/');
    }

    /** Remote root je vždy „/“-prefixovaná cesta bez koncového lomítka (kromě rootu). */
    private static function normalizeRemote(string $remote): string
    {
        $remote = '/' . trim($remote, '/');
        return $remote === '/' ? '/' : rtrim($remote, '/');
    }
}
