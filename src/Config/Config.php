<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Config;

use RuntimeException;

/**
 * Project client configuration, loaded from a PHP file that returns an array.
 *
 * The rich config (local↔remote mapping, ignore, protect) lives on the client;
 * the server agent only knows its public key, remote root and protect-list.
 */
final class Config
{
    /** @var list<string> */
    public readonly array $ignore;

    /** @var list<string> */
    public readonly array $protect;

    /** @var list<string> */
    public readonly array $compressSkipExt;

    /**
     * @param mixed[] $ignore
     * @param mixed[] $protect
     * @param mixed[] $compressSkipExt
     */
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
        public readonly ?string $configPath = null,
    ) {
        $this->ignore = array_values(array_map('strval', $ignore));
        $this->protect = array_values(array_map('strval', $protect));
        $this->compressSkipExt = array_values(array_map(
            static fn($e): string => strtolower(ltrim((string) $e, '.')),
            $compressSkipExt,
        ));
    }

    /**
     * Loads and validates the config from a PHP file (`return [...]`).
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Configuration file does not exist: $path");
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException("Configuration file must return an array (`return [...]`): $path");
        }

        $require = static function (string $key) use ($data, $path): mixed {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                throw new RuntimeException("Required config key '$key' is missing: $path");
            }
            return $data[$key];
        };

        $mapping = $data['mapping'] ?? [];
        if (!is_array($mapping) || !isset($mapping['local'])) {
            throw new RuntimeException("The 'mapping.local' key is required: $path");
        }

        $local = self::normalizeDir((string) $mapping['local']);
        if (!is_dir($local)) {
            throw new RuntimeException("mapping.local does not exist or is not a directory: $local");
        }

        $configReal = realpath($path);

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
            configPath: $configReal !== false ? $configReal : $path,
        );
    }

    /**
     * The private key is required for every operation except `install`.
     */
    public function requirePrivateKey(): string
    {
        if ($this->privateKey === null) {
            throw new RuntimeException(
                "The 'privateKey' is missing from the config. Generate it with the `install` command and add it to the config.",
            );
        }
        return $this->privateKey;
    }

    private static function normalizeDir(string $dir): string
    {
        $real = realpath($dir);
        return $real !== false ? $real : rtrim($dir, '/');
    }

    /** The remote root is always a "/"-prefixed path without a trailing slash (except for the root). */
    private static function normalizeRemote(string $remote): string
    {
        $remote = '/' . trim($remote, '/');
        return $remote === '/' ? '/' : rtrim($remote, '/');
    }
}
