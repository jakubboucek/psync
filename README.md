# php-sync

Bidirectional, rsync-like file synchronization over an HTTP agent for **legacy hostings where FTP is the only access** (no SSH/rsync).

A single self-contained PHP file (the agent) is uploaded to the server once over FTP. The client (a CLI installed as `composer global`) then calls it over HTTP(S) and can **compare, upload, and download** files — even for applications with tens of thousands of files, while respecting the hard limits of shared hostings.

- **Client:** PHP 8.4+
- **Agent (server):** PHP 7.4+, no Composer dependencies (just `ext-sodium`)

## How it works

1. `php-sync install` generates an **Ed25519** key pair and a server agent containing the **public** key. The private key goes into your config (and nowhere else).
2. You upload the agent over FTP to the directory that should become the remote root.
3. The client signs every request with the private key; the agent verifies it with the public key. **A leaked agent does not allow an attacker to sign anything.**

## Installation

```bash
composer global require jakubboucek/php-sync
```

## Configuration

`php-sync install` generates `php-sync.php`. Fill in `url` and `mapping.local`:

```php
<?php
return [
    'url'        => 'https://example.com/agent.php',
    'privateKey' => 'base64…',                 // from install, keep secret
    'mapping'    => ['local' => __DIR__, 'remote' => '/'],
    'ignore'     => ['/.git', '/vendor', '*.log', '/temp', '/uploads'],
    'protect'    => ['/uploads', '/temp'],     // never deleted
    'checksum'   => false,                       // like rsync -c
    'compress'   => true,                        // GZ during transfer
    'compressSkipExt' => ['jpg','png','zip','gz','pdf','mp4'],
];
```

## Commands

```bash
php-sync install [-o agent.php] [-c php-sync.php]   # generate agent + keys
php-sync compare  [path] [-c …] [-v] [--checksum]   # list differences (transfers nothing)
php-sync upload   [path] [--delete] [--dry-run]     # local → remote
php-sync download [path] [--delete] [--dry-run]     # remote → local
```

- The optional **`path`** limits the operation to a subdirectory/file.
- **`--delete`** deletes extra files (on the other side), **except for `protect`**. Without it, nothing is deleted.
- **`--dry-run`** only prints what would be transferred/deleted.
- **`--checksum`** always computes the hash (ignoring mtime and cache).

`compare` legend: `>` local only · `<` server only · `M` differs · `=` identical.

## How changes are detected (2 phases)

1. **Fast phase** — listing (name, size, mtime) on both sides.
2. **Hash phase** — only for files with identical size and differing mtime, md5 is computed in batches (≤ 100 MB / ≤ 1000). The result is cached (`.php-sync-state.json`), so it is not hashed a second time.

Every transferred file gets its **mtime from the source**, and the write is **atomic** (tmp + rename).

## Security

- **Ed25519** signatures; the server holds only the public key. It works even over plain HTTP (the signature protects both integrity and identity, while a timestamp + nonce prevent replay).
- **Keep the private key in the config secret** and out of public git.
- All requests are **POST** (the action in the body, not the URL) for the sake of WAFs; upload has GZ enabled by default even for text, so that a WAF does not flag the PHP source as RCE.
- The agent strictly **sanitizes paths** (no `../`, everything stays inside the root).

## Limits and notes

- **Uploading a file larger than the server's `post_max_size`** cannot pass through the bulk mechanism — the file is skipped with a clear message (chunked upload is a possible future extension). Downloading large files works.
- Content and mtime are transferred; **owner/permissions are not** (HTTP/FTP cannot do that). Symlinks are not followed.
- Operations are **resumable**: after a premature server crash, just rerun the command (completed files are skipped, the rest is computed).

## Development and testing

The test environment is in `docker-compose.yml` (server = PHP 7.4 Apache, client = PHP 8.4 CLI):

```bash
docker compose up -d
docker compose exec client php bin/php-sync compare -c tests/sync.config.php
composer check        # parallel-lint + PHPStan (level 8 + strict) + Nette Tester
```
