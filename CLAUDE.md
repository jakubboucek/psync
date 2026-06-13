# psync — development notes

rsync-like bidirectional synchronization over an HTTP agent for legacy hostings (FTP-only).
Client PHP 8.4+, agent PHP 7.4+ (no dependencies, just ext-sodium). Detailed user
documentation is in [README.md](README.md); here are the things important for editing the code.

## Architecture

- **Client** (`src/`, namespace `JakubBoucek\Psync\`) — Symfony Console, installable as `composer global`.
- **Agent** (`agent/agent.template.php`) — a template; `install` bakes in the public key and
  the protect-list (placeholders `PSYNC_PUBLICKEY_PLACEHOLDER` and `/* PSYNC_PROTECT */`).
  Rendered by `JakubBoucek\Psync\Install\AgentBuilder`. `install` writes it under a **randomized
  filename** `psync-agent-<nonce>.php` (6 hex chars via `random_bytes(3)`) so the URL can't be
  scanned for; pass `-o` to override. The template's header comment is an on-purpose reassurance for
  anyone auditing the site later (it's a maintenance tool, not a webshell; safe to delete) — keep it.
- The rich config (mapping, ignore) lives **on the client**; the agent only knows the public key, its own root
  (`__DIR__`), and the protect-list. That is why `install` is repeated only on key rotation / protocol change.

## Protocol (version in `Protocol::VERSION`)

- Everything is **POST**, a single endpoint. The action is in the JSON body; only **upload** carries it in the
  `X-Sync-Action` header (the body is binary).
- **Ed25519 signature** over the canonical message `action ⧺ ts ⧺ nonce ⧺ sha256(body)`
  (`Signer::canonical` on the client, mirrored by hand in the agent's `authenticate()`).
  **When you change this message, update BOTH sides and bump `Protocol::VERSION`.**
- Endpoints: `capabilities`, `list`, `hash` (NDJSON), `download` (binary framing response),
  `upload` (binary framing body, NDJSON response), `delete` (NDJSON).

### Wire formats (held in two places — client `Wire`/`FrameWriter`, agent inline — must be byte-identical!)
- **NDJSON**: paths are **base64** (names on legacy servers tend to be non-UTF8/Windows-1250,
  and `json_encode` would fail on them). A listing ends with `{"end":true}`.
- **Binary frame**: `[u32 pathLen][path][u8 flags][u64 mtime][u64 origSize][u64 payloadLen][16 md5]`,
  big-endian (`pack N/C/J`), the fixed part after the path = **41 B**. `flags` bit0 = gzip payload.
  Definitions: `Wire::packFrameHeader/readFrameHeader`; agent `frame_pack_header/frame_read_header`.

## Key mechanisms

- **2-phase comparison** (`Comparator`): listing → candidates (identical size, different mtime) →
  md5 (locally + in batches on the server, ≤100 MB/≤1000). `--checksum` hashes everything.
- **StateCache** (`.psync-state.json` in the local root, auto-ignored): key `base64(rel)`,
  the equality verdict is reused only when the local size+mtime and the remote mtime match. This way a
  file that only has a differing mtime (FTP clock mismatch) is not hashed repeatedly.
- **Transfers**: per-file frame, optionally gzip (deflate/inflate streamed through a temp file).
  The target is always written **atomically** (tmp + rename) + `touch()` the source mtime + md5 verification.
- **Batching & limits**: the client reads `capabilities` and batches accordingly. Upload is limited
  by the real `post_max_size` (the request body) — **a file larger than post_max_size will not pass through bulk**
  and `Uploader` skips it with a message (chunked upload = a future TODO). Download is not limited by this.
- **Resumability is the primary** correctness guarantee; the NDJSON stream is only for progress. After a server
  crash, the command is rerun (idempotent; finished files are skipped).

## Watch out (why it is the way it is)

- **Capabilities reads the original values BEFORE `prepare_runtime()`**: `set_time_limit(0)` zeroes out
  `max_execution_time` and the agent disables `zlib.output_compression` — that is why both are captured into
  `$CONFIG['_maxExecutionTime']` / `['_zlibOutputCompression']` at the start. Do not move it after
  `prepare_runtime()`, otherwise capabilities will lie.
- **zlib.output_compression** is disabled at runtime by the agent (`ini_set` + `no-gzip` + `Content-Encoding: identity`),
  to avoid double compression. Verified to work even with `zlib.output_compression=On`.
- **The signature/key length** is checked in the agent before `sodium_*verify` — otherwise a malformed
  signature would throw an exception → HTTP 500 instead of 403.
- **Deletion**: `protect` is filtered by both client and agent (two lines of defense). Protect prevents **deletion**,
  not overwriting during download (extra protected directories typically belong to `ignore` as well).
- **The macOS client cannot create a non-UTF8 name** (APFS) — so Windows-1250 names are covered only by
  `tests/Unit/Wire.phpt` at the Wire level, not by integration tests.
- **Unicode NFC/NFD**: macOS lists names as NFD, Linux servers as NFC, so the same file would otherwise be
  both `>` and `<`. `Comparator` keys its maps by `PathNormalizer::key()` (NFC, needs `ext-intl`; falls back to
  raw bytes without it or for non-UTF8), but keeps the **original per-side bytes** in `FileEntry->path` for all
  I/O. Transfers carry an explicit `TransferItem{sourcePath,targetPath}` so a modified accented file overwrites
  the existing entry on the far side instead of creating a duplicate; the agent always receives the remote
  original bytes for `hash`/`download`/`delete`.

## Quality control and testing

- **`composer check`** = `lint` (parallel-lint, incl. .phpt) + `phpstan` + `tester`. Run it before committing.
- **PHPStan level 8 + strict-rules** on `src/` + `bin/` (`phpstan.neon`). The agent is excluded (different target
  version, procedural) — it is covered by parallel-lint, linting on 7.4, and integration tests. Level `max` is
  deliberately not used: it would report "cast mixed" at the JSON/config boundaries, where coercion is intentional.
- **Unit tests** (Nette Tester, `tests/Unit/*.phpt`): `IgnoreMatcher`, `Wire`, `Signer`, `FrameWriter`,
  `StateCache`, `Config` — pure logic without network/IO (except temp files).
- `php tests/agent_smoke.php render|check` — an integration test of the agent (capabilities/list/hash/auth)
  against a running server; requires docker compose.
- `docker-compose.yml` — server PHP 7.4 (`jakubboucek/lamp-devstack-php:7.4-legacy`, note that
  legacy versions have the suffix `-legacy`; the CLI variant is `-legacy-cli`) + client PHP 8.4.
  Hosting limits are simulated by `tests/docker/limits.ini` (post_max_size=4M…); the variant
  `limits-zlib.ini` tests the zlib workaround.
- **Opcache**: after re-rendering the agent in `tests/remote`, a `docker compose restart server` is required,
  otherwise the server may keep the old version for a while.
- `tests/{local*,remote}/`, `tests/*.config.php`, and `tests/.smoke_priv` are in `.gitignore`
  (they contain generated content and private keys).
