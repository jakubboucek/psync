# PHP sync (rsync for PHP) tool for dummy webhostings

*A tool for automated deploy/download of PHP applications source code between local PC and dummy webhosting.*

There is nothing worse than babysitting a PHP application on a hosting that, in this day and age, still
speaks nothing but FTP. No SSH, no rsync, no Git deploy — just a lonely FTP port and your patience.
You drag files in Total Commander, never quite sure what is *actually* on the server, and if you ever
need to pull a changed file back *down* to compare it… good luck. (Editing straight on the server and
then trying to keep things in sync is, of course, even worse ;-)

**psync** is rsync for exactly these dummy webhostings. You upload one small PHP file (the *agent*) over
FTP once, and from then on you drive it over HTTP from your machine. Unlike one-way FTP deployers, psync
is **bidirectional**: it can `compare` both sides, `upload` your local changes, and `download` whatever
got changed on the server — even for applications with tens of thousands of files, and while politely
respecting the tiny time/memory/upload limits of cheap shared hosting.

- **Client:** PHP 8.4+ (installed as `composer global`)
- **Agent (server):** PHP 7.4+, no Composer dependencies (just `ext-sodium`)

## How it works

1. **Install once.** `psync install` generates an **Ed25519** key pair and renders the agent — a single
   self-contained PHP file containing only the **public** key. You upload it via FTP to the directory
   that should become the remote root. The **private** key goes into your local config and nowhere else,
   so even a leaked agent file lets nobody forge a request. The agent gets a **randomized filename**
   (`psync-agent-<nonce>.php`) so its URL can't be scanned for, and it carries a header comment that
   tells anyone who later stumbles on it that it is a maintenance tool — not a backdoor — and is safe
   to delete.

2. **Every call is a signed HTTP request.** The client signs each request with the private key; the agent
   verifies it with the public key, plus a timestamp and nonce against replay. It therefore works even
   over plain HTTP, which many of these hostings still serve.

3. **Comparison is two-phase**, the way rsync stays fast. First a quick listing of name + size + mtime on
   both sides. Only for files of equal size but different mtime does the agent compute an `md5` — in
   batches sized to fit the server's limits. A local state cache remembers the verdict, so a file whose
   only difference is a wobbly FTP mtime is not re-hashed on every run.

4. **Transfers are streamed and safe.** Files move in a compact binary framing (optionally gzipped),
   never loaded whole into memory. Each file is written **atomically** (temp file + rename) and stamped
   with the source's mtime. Deletion is opt-in (`--delete`) and shielded by a protect-list.

5. **It survives crashes.** Because shared hosting loves to kill a request mid-flight, every operation is
   idempotent and **resumable** — if it dies, just run it again and the finished files are skipped.

## Installation

```bash
composer global require jakubboucek/psync
```

## Configuration

`psync install` generates `.psync.php`. Fill in `url` and `mapping.local`:

```php
<?php
return [
    'url'        => 'https://example.com/psync-agent-XXXXXX.php',
    //                       ^^^^^^^^^^^ - put here domain of your website
    'privateKey' => 'base64…',                   // from install, keep secret
    'mapping'    => [
        'local' => __DIR__,                      // complete path to the local website root
        'remote' => '/'
    ],
    'ignore'     => ['/.git', '/.psync.php', '*.log', '/temp', '/uploads'],
    'protect'    => ['/uploads', '/temp'],       // never deleted
    'checksum'   => false,                       // like rsync -c
    'compress'   => true,                        // GZ during transfer
    'compressSkipExt' => ['jpg','png','zip','gz','pdf','mp4'],
];
```

## Commands

```bash
psync install [-o <file>] [-c .psync.php]        # generate agent (randomized name) + keys
psync compare  [path] [-c …] [-v] [--checksum]   # list differences (transfers nothing)
psync upload   [path] [--delete] [--dry-run]     # local → remote
psync download [path] [--delete] [--dry-run]     # remote → local
```

- The optional **`path`** limits the operation to a subdirectory/file.
- **`--delete`** deletes extra files on the other side, **except for `protect`**. Without it, nothing is deleted.
- **`--dry-run`** only prints what would be transferred/deleted.
- **`--checksum`** always computes the hash (ignoring mtime and the cache), like `rsync -c`.

`compare` legend: `>` local only · `<` server only · `M` differs · `=` identical.

## Security

- **Ed25519** signatures; the server holds only the public key. Works even over plain HTTP (the signature
  protects both integrity and identity, while a timestamp + nonce prevent replay).
- **Keep the private key in your config secret** and out of public git.
- All requests are **POST** (the action in the body, not the URL) for the sake of WAFs; upload has GZ
  enabled by default even for text, so a WAF does not flag the PHP source as an RCE upload.
- The agent strictly **sanitizes paths** (no `../`, everything stays inside the root).

## Limits and notes

- **Uploading a file larger than the server's `post_max_size`** cannot pass through the bulk mechanism —
  the file is skipped with a clear message (chunked upload is a possible future extension). Downloading
  large files works.
- Content and mtime are transferred; **owner/permissions are not** (HTTP/FTP cannot do that). Symlinks
  are not followed.
- Operations are **resumable**: after a premature server crash, just rerun the command.

## Development and testing

The test environment is in `docker-compose.yml` (server = PHP 7.4 Apache, client = PHP 8.4 CLI):

```bash
docker compose up -d
docker compose exec client php bin/psync compare -c tests/sync.config.php
composer check        # parallel-lint + PHPStan (level 8 + strict) + Nette Tester
```
