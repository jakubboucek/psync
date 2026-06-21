<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Config;

use JakubBoucek\Psync\Sync\PathRelativizer;
use RuntimeException;

/**
 * Project client configuration, loaded from a PHP file that returns an array.
 *
 * The rich config (which tree to sync, ignore, protect) lives on the client; the
 * server agent only knows its public key, its baked scope and the protect-list.
 *
 * Three directories matter (all relative to the config file's own directory,
 * the "project-root"):
 *  - sync-root  – the top of the synchronized tree (= the agent's remote root),
 *  - agent-dir  – where the agent file physically lives (its deploy location),
 *  - the agent URL – the public HTTP endpoint, stored verbatim.
 * The agent's baked scope is derived as the path agent-dir -> sync-root; it is
 * NOT stored (no third source of truth) but recomputed on demand.
 */
final readonly class Config
{
    /** @var list<string> */
    public array $ignore;

    /** @var list<string> */
    public array $protect;

    /** @var list<string> */
    public array $compressSkipExt;

    /**
     * @param mixed[] $ignore
     * @param mixed[] $protect
     * @param mixed[] $compressSkipExt
     */
    public function __construct(
        public string $url,
        public ?string $privateKey,
        public string $localRoot,
        public string $syncRoot,
        public string $agentDir,
        public string $agentFile,
        array $ignore = [],
        array $protect = [],
        public bool $checksum = false,
        public bool $allowDelete = false,
        public bool $testMode = false,
        public bool $compress = true,
        array $compressSkipExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'gz', 'pdf', 'mp4', 'mp3'],
        public bool $serverGzipWorkaround = false,
        public ?string $configPath = null,
    ) {
        $this->ignore = array_values(array_map(strval(...), $ignore));
        $this->protect = array_values(array_map(strval(...), $protect));
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

        // The v1.0 layout (a `mapping` block + `url`) is incompatible with v1.1+.
        if (isset($data['mapping']) || !isset($data['agentUrl'])) {
            throw new RuntimeException(
                "Configuration format changed in v1.1: replace the 'mapping' block with "
                . "'syncRoot', 'agentDir', 'agentFile' and 'agentUrl', or re-create the config "
                . "with `psync install --force`: $path",
            );
        }

        $url = (string) $data['agentUrl'];
        if ($url === '') {
            throw new RuntimeException("Required config key 'agentUrl' is empty: $path");
        }

        $configReal = realpath($path);
        $configReal = $configReal !== false ? $configReal : $path;
        $projectRoot = dirname($configReal);

        $syncRoot = trim((string) ($data['syncRoot'] ?? ''), '/');
        $local = self::resolveUnder($projectRoot, $syncRoot);
        if (!is_dir($local)) {
            throw new RuntimeException("syncRoot does not exist or is not a directory: $local");
        }

        return new self(
            url: $url,
            privateKey: isset($data['privateKey']) ? (string) $data['privateKey'] : null,
            localRoot: $local,
            syncRoot: $syncRoot,
            agentDir: trim((string) ($data['agentDir'] ?? ''), '/'),
            agentFile: (string) ($data['agentFile'] ?? ''),
            ignore: (array) ($data['ignore'] ?? []),
            protect: (array) ($data['protect'] ?? []),
            checksum: (bool) ($data['checksum'] ?? false),
            allowDelete: (bool) ($data['allowDelete'] ?? false),
            testMode: (bool) ($data['testMode'] ?? false),
            compress: (bool) ($data['compress'] ?? true),
            compressSkipExt: (array) ($data['compressSkipExt']
                ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'gz', 'pdf', 'mp4', 'mp3']),
            serverGzipWorkaround: (bool) ($data['serverGzipWorkaround'] ?? false),
            configPath: $configReal,
        );
    }

    /**
     * The agent's baked scope: the relative path from the agent's directory to
     * the sync root. Recomputed (never stored) so it can never drift from the
     * directories it is derived from. The capabilities cross-check compares this
     * against the value the deployed agent reports.
     */
    public function scopeRelPath(): string
    {
        return PathRelativizer::relativize($this->agentDir, $this->syncRoot);
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

    /** Resolves a project-root-relative path, falling back to a lexical join when realpath fails. */
    private static function resolveUnder(string $base, string $rel): string
    {
        $candidate = $rel === '' ? $base : $base . '/' . $rel;
        $real = realpath($candidate);
        return $real !== false ? $real : rtrim($candidate, '/');
    }
}
