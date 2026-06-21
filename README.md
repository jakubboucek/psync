# PHP sync (rsync for PHP) tool for crappy webhostings

*A tool for automated deploy/download of PHP applications source code between local PC and crappy webhosting.*

There is nothing worse than babysitting a PHP application on a hosting that, in this day and age, still
speaks nothing but FTP. No SSH, no rsync, no Git deploy — just a lonely FTP port and your patience.
You drag files in Total Commander, never quite sure what is *actually* on the server, and if you ever
need to pull a changed file back *down* to compare it… good luck. (Editing straight on the server and
then trying to keep things in sync is, of course, even worse ;-)

**psync** is rsync for exactly these crappy webhostings. You upload one small PHP file (the *agent*) over
FTP once, and from then on you drive it over HTTP from your machine. Unlike one-way FTP deployers, psync
is **bidirectional**: it can `compare` both sides, `upload` your local changes, and `download` whatever
got changed on the server — even for applications with tens of thousands of files, and while politely
respecting the tiny time/memory/upload limits of cheap shared hosting.

- **Client:** PHP 8.4+ (installed as `composer global`)
- **Agent (server):** PHP 7.4+, no Composer dependencies (just `ext-sodium`)

## How it works

1. **Install once.** `psync install` generates an **Ed25519** key pair and renders the agent — a single
   self-contained PHP file containing only the **public** key. You upload it via FTP into your
   **agent-dir** (where it must be reachable over HTTP). The **private** key goes into your local config
   and nowhere else, so even a leaked agent file lets nobody forge a request. The agent gets a
   **randomized filename** (`psync-agent-<nonce>.php`) so its URL can't be scanned for, and it carries a
   header comment that tells anyone who later stumbles on it that it is a maintenance tool — not a
   backdoor — and is safe to delete. After a version bump, `psync re-install` re-renders the agent
   reusing your layout (rotating the key by default) — you re-upload it.

   The agent's reach is a **fixed scope baked in at install** — the path from where the agent file lives
   (**agent-dir**) to the top of the synchronized tree (**sync-root**). The two need not coincide: the
   synced tree may sit **above** the agent (frameworks like Nette/Laravel/Symfony keep their code above
   the public dir), **below** it (manage just `system/logs/` from an agent at the site root), or on a
   **sibling** branch. The scope is hardcoded and never taken from a request, so it stays a fixed
   boundary; the client cross-checks it against your config on every run.

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

`psync install` generates and fills in `.psync.php`. All paths are **relative to the config file's own
directory** (the project-root):

```php
<?php
return [
    'agentUrl'   => 'https://example.com/psync-agent-XXXXXX.php', // public URL of the agent (used as-is)
    'privateKey' => 'base64…',                   // from install, keep secret
    'syncRoot'   => '',                          // top of the synchronized tree ('' = this directory)
    'agentDir'   => 'www',                        // where the agent file is deployed ('' = sync-root)
    'agentFile'  => 'psync-agent-XXXXXX.php',      // basename, used by re-install
    'ignore'     => ['/.git', '*.log', '/temp', '/uploads'],
    'protect'    => ['/uploads', '/temp'],       // never deleted
    'checksum'   => false,                       // like rsync -c
    'compress'   => true,                        // GZ during transfer
    'compressSkipExt' => ['jpg','png','zip','gz','pdf','mp4'],
];
```

### Config keys

- **`agentUrl`** – the agent's public URL, used verbatim. With `install --host` it is composed for you; with `--agent-url` you give it explicitly (e.g. when a rewrite rule routes to the agent).
- **`privateKey`** – base64 Ed25519 key from `install`; signs every request. **Keep secret** (the config file is auto-excluded from sync so it can never be uploaded).
- **`syncRoot`** – the top of the synchronized tree, relative to this file's directory (`''` = the project-root). This is the only directory the agent may touch.
- **`agentDir`** – where the agent file is deployed, relative to this file's directory (`''` = the sync-root). The agent's scope is derived as the path agent-dir → sync-root.
- **`agentFile`** – the agent's filename; `re-install` rewrites this file.
- **`ignore`** / **`protect`** – see below.
- **`checksum`** – always hash files instead of trusting size+mtime (like `rsync -c`); slower.
- **`compress`** / **`compressSkipExt`** – gzip the payload during transfer, except for the listed (already-compressed) extensions.

> The filesystem `agentDir` (used to compute the scope) and the public `agentUrl` are **independent** — psync does not track how your DocumentRoot maps to the filesystem, so it only needs the URL that reaches the agent.

#### `ignore` vs `protect`

These two look similar but do **completely different** things:

- **`ignore`** – the path is **entirely outside synchronization**. It is never uploaded, downloaded, compared, or deleted — psync acts as if it didn't exist on either side. Use it for build artifacts, logs, caches, and anything the server owns (e.g. user uploads). (`.psync-state.json` and the config file itself are always ignored automatically.)
- **`protect`** – the path **stays in sync** (it can still be created and **overwritten**), it is only shielded from **deletion** by `--delete`. Without `--delete` nothing is deleted anyway, so `protect` only matters together with `--delete`.

> ⚠️ **`protect` does not prevent overwriting** — only deletion. If you want a directory genuinely left alone (typically user-generated content like `/uploads`), put it in **`ignore`**. The example above lists `/uploads` and `/temp` in **both**: `ignore` keeps psync from touching them, and `protect` is the extra safety net so they survive even if they are ever removed from `ignore`.

**Pattern syntax** (same for both lists): a pattern starting with `/` is anchored to the root (`/temp` matches `temp` and everything under it); a pattern without `/` matches any path segment or basename (`*.log`, `.git`); globs `*`, `?`, `[...]` are supported.

> **`/temp` vs `/temp/*`** — because directories are synchronized entities (see below), these differ:
> `/temp` ignores the folder **itself**, so it is never created on the other side; `/temp/*` keeps the
> empty `temp/` folder (it **is** created) but ignores everything inside it. Use the `/*` form for a
> directory the application expects to exist but whose contents are server-owned (caches, runtime logs).

#### Directories

psync synchronizes **directories as first-class entries**, so an **empty** directory is created on the
other side too (handy for `/temp`, `/log`, or any placeholder folder the application expects to exist).
With `--delete`, a directory that is missing on the source is removed on the target — but **only if it is
empty**: deletion is **non-recursive**, contents are deleted first and then the directory. If a directory
still holds files that your `ignore` mask hid from the comparison (e.g. a stray `*.log`), the `rmdir`
**fails on purpose** (reported as an error, the run continues and exits non-zero) — that is a deliberate
state for you to resolve, not something psync silently force-deletes. A path that is a file on one side
and a directory on the other is reported as a **type conflict** and skipped (never auto-resolved).

## Commands

```bash
psync install    [--host <h> | --agent-url <u>] \
                 [--sync-root <dir>] [--agent-dir <dir>] [--agent-file <name>] \
                 [--config .psync.php] [--force]              # generate agent + keys, write the config

psync re-install [--preserve-key] [--config .psync.php]       # regenerate the agent file

psync compare    [path] [--checksum]                          # list differences (transfers nothing)

psync upload     [path] [--checksum] [--delete] [--dry-run]   # local → remote

psync download   [path] [--checksum] [--delete] [--dry-run]   # remote → local
```

- The optional **`path`** limits the operation to a subdirectory/file.
- **`--delete`** deletes extra files on the other side, **except for `protect`**. Without it, nothing is deleted.
- **`--dry-run`** only prints what would be transferred/deleted.
- **`--checksum`** always computes the hash (ignoring mtime and the cache), like `rsync -c`.

`compare` legend: `>` local only · `<` server only · `M` differs · `=` identical.

- **`install`** is a one-time bootstrap: it generates a fresh key pair and a randomly-named agent, and
  writes (or, if you confirm, **overwrites**) `.psync.php`. Run on an existing config it first asks whether
  you actually meant `re-install`. The HTTP endpoint is given either as **`--host`** (the URL is composed
  by convention, e.g. `--host example.com` or `--host example.com/tools`) or **`--agent-url`** (the full
  URL, stored verbatim) — not both. **`--sync-root`** / **`--agent-dir`** describe the layout (relative to
  the project-root; defaults: sync-root = project-root, agent-dir = sync-root); **`--agent-file`** overrides
  the randomized name (a directory in it is taken as the agent-dir).
- **`re-install`** (alias **`reinstall`**; think `apt reinstall`) re-renders the agent — after a
  protocol-version bump (a `psync …` run will tell you when one is needed), a package security fix, or just
  to rotate the key — reusing the **filename and scope** from the config. **By default it rotates the key**:
  a fresh pair is generated, the new public key goes into the agent and the new private key replaces the old
  one in `.psync.php` (surgically, so your comments and other keys stay). Pass **`--preserve-key`** to keep
  the existing key untouched. Either way, **re-upload the regenerated agent** — after a rotation the server
  rejects every request (HTTP 403) until you do.

Example layouts (run from the project-root):

```bash
psync install --host example.com                                  # agent at the root, syncs it (WordPress-style)
psync install --host example.com --agent-dir www                  # agent in www/, syncs the app above it (Nette/Symfony-style)
psync install --host example.com --sync-root system/logs --agent-dir .   # agent at the root, syncs only system/logs/
psync install --host example.com/tools --sync-root system/logs --agent-dir tools  # agent in tools/, manages system/logs/
```

### A worked example (framework app, code above the public dir)

The most common enhanced case: a Nette/Symfony-style project whose application code and dependencies live
**above** the public directory, with the agent deployed into the public dir (= DocumentRoot):

```text
my_project/                        ← project-root  (run psync here; holds .psync.php)
├── .psync.php                       config — holds the private key, never synced
│
├── docker-compose.yml               some local tooling — stays on your machine, out of the sync-root
├── phpstan.neon                     
│
└── web/                           ← sync-root  (the synchronized tree = the agent's reach)
    │
    ├── app/                         the application code, above the public dir; synced but never reachable over HTTP
    │   └── bootstrap.php            
    ├── vendor/                      
    │   └── ...
    │
    └── www/                       ← agent-dir  (DocumentRoot; the agent is deployed here)
        ├── index.php                front controller
        └── psync-agent-ab12cd.php   the agent → https://example.com/psync-agent-ab12cd.php
```

From the project-root:

```bash
psync install --host example.com --sync-root web --agent-dir web/www
```

This deploys the agent into `web/www/` (served at `https://example.com/`) and synchronizes the whole `web/`
tree — including `app/` and `vendor/`, which sit **above** the public dir and are never reachable over HTTP.
`docker-compose.yml` and `phpstan.neon` stay on your machine because they live at the project-root, outside
the sync-root. The agent's baked scope is `..` — one level up from `www/` to `web/`.

## Security

- **Ed25519** signatures; the server holds only the public key. Works even over plain HTTP (the signature
  protects both integrity and identity, while a timestamp + nonce prevent replay).
- **Keep the private key in your config secret** and out of public git.
- **Rotate the key** periodically: `psync re-install` does it by default (then re-upload the agent). Use `--preserve-key` only when you explicitly want to keep the current key.
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
