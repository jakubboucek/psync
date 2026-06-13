<?php
/**
 * psync agent — https://github.com/jakubboucek/psync
 *
 * ── This is NOT a backdoor or a webshell. ──────────────────────────────────
 *
 * It is a legitimate maintenance tool that the DEVELOPER of this website uses
 * to synchronize the site's source code over HTTP — think "rsync for hostings
 * that only offer FTP". If you found this file while auditing the application
 * and wondered why it is here: this is why. It was placed here on purpose.
 *
 * The application itself NEVER includes, requires, calls, or depends on this
 * file in any way. It is completely standalone.
 *
 * SAFE TO DELETE: removing this file does not affect the website at all — the
 * only consequence is that the developer loses their sync access to the site.
 * If you have parted ways with the developer who installed it, you SHOULD
 * delete it to revoke that access.
 *
 * Why it is safe to leave running: this file holds ONLY an Ed25519 PUBLIC key.
 * Every request must be signed with the matching PRIVATE key, which only the
 * developer's client holds (it is never stored here). Possessing or reading
 * this file therefore does not let anyone control the server.
 *
 * Learn more / verify the source: https://github.com/jakubboucek/psync
 *
 * ───────────────────────────────────────────────────────────────────────────
 * GENERATED FILE — do not edit by hand; regenerate with `psync install`.
 * Target compatibility: PHP 7.4+ (no Composer dependencies, only ext-sodium).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration (values filled in by `install`)
// ---------------------------------------------------------------------------
$CONFIG = array(
    'publicKey'       => 'PSYNC_PUBLICKEY_PLACEHOLDER', // base64 of the public key
    'protocolVersion' => 1,
    'root'            => __DIR__,                          // remote root = agent's directory
    'protect'         => array(/* PSYNC_PROTECT */),     // glob patterns that are never deleted
);

// ---------------------------------------------------------------------------
// Protocol constants (must match the client – JakubBoucek\Psync\Protocol\Protocol)
// ---------------------------------------------------------------------------
const HEADER_TS = 'X-Sync-Ts';
const HEADER_NONCE = 'X-Sync-Nonce';
const HEADER_SIG = 'X-Sync-Sig';
const HEADER_ACTION = 'X-Sync-Action';
const TIME_WINDOW = 300;
const FLAG_GZIP = 1;
const HASH_ALGO = 'md5';
const CHUNK = 65536;

(static function (array $CONFIG): void {
    // Capture the original values BEFORE prepare_runtime() overwrites them:
    //  - max_execution_time is zeroed by set_time_limit(0)
    //  - zlib.output_compression is disabled by the agent at runtime
    // The client needs to know them (batching, server info).
    $CONFIG['_maxExecutionTime'] = (int) ini_get('max_execution_time');
    $CONFIG['_zlibOutputCompression'] = ini_get('zlib.output_compression') ? true : false;
    prepare_runtime();

    try {
        // Upload has a binary body and the action in a header; JSON actions carry the action in the body.
        $actionHeader = header_value(HEADER_ACTION);
        $isUpload = ($actionHeader === 'upload');
        $body = read_body($isUpload); // JSON action = string; upload = ['tmp' => path, 'sha256' => hex]
        $action = detect_action($actionHeader, $body);
        authenticate($CONFIG, $action, $body);
        dispatch($CONFIG, $action, $body);
    } catch (AgentError $e) {
        send_error($e->getCode() ?: 400, $e->getMessage());
    } catch (\Throwable $e) {
        send_error(500, 'Internal agent error: ' . $e->getMessage());
    }
})($CONFIG);


// ===========================================================================
// Runtime preparation
// ===========================================================================

/**
 * Disables the server's transparent compression/buffering so it does not
 * collide with our own stream and per-file GZ, and tries to cancel the time
 * limit (best-effort).
 */
function prepare_runtime(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (set_time_limit_available()) {
        @set_time_limit(0);
    }
    @ignore_user_abort(false);
}

function set_time_limit_available(): bool
{
    if (!function_exists('set_time_limit')) {
        return false;
    }
    $disabled = explode(',', (string) ini_get('disable_functions'));
    foreach ($disabled as $fn) {
        if (trim($fn) === 'set_time_limit') {
            return false;
        }
    }
    return true;
}


// ===========================================================================
// Request: action, body, authentication
// ===========================================================================

class AgentError extends \RuntimeException
{
}

/**
 * Determines the action. Upload carries it in a header; JSON actions in the
 * body's 'action' field. We take the action from the (not yet verified) body,
 * but the body is part of the signature via its sha256 digest – any change to
 * the action in the body breaks signature verification.
 *
 * @param string|array $body
 */
function detect_action(?string $actionHeader, $body): string
{
    if ($actionHeader !== null && $actionHeader !== '') {
        return $actionHeader;
    }
    if (is_string($body)) {
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['action'])) {
            return (string) $data['action'];
        }
    }
    return '';
}

/**
 * Reads the request body.
 *  - Upload (binary): streams php://input into a temp file while computing
 *    sha256 (for the signature) – never holds the whole body in memory.
 *  - Others (JSON): the small body is read into memory.
 *
 * @return string|array
 */
function read_body(bool $isUpload)
{
    if ($isUpload) {
        $tmp = tempnam(sys_get_temp_dir(), 'psync_up_');
        if ($tmp === false) {
            throw new AgentError('Cannot create temporary file.', 500);
        }
        $in = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');
        $ctx = hash_init('sha256');
        if ($in === false || $out === false) {
            throw new AgentError('Cannot open input/temporary stream.', 500);
        }
        while (!feof($in)) {
            $chunk = fread($in, CHUNK);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                hash_update($ctx, $chunk);
                fwrite($out, $chunk);
            }
        }
        fclose($in);
        fclose($out);
        return array('tmp' => $tmp, 'sha256' => hash_final($ctx));
    }

    // JSON action – the body is small.
    $raw = file_get_contents('php://input');
    return $raw === false ? '' : $raw;
}

/**
 * Verifies the signature, the time window and (best-effort) the replay nonce.
 *
 * @param string|array $body
 */
function authenticate(array $CONFIG, string $action, $body): void
{
    $ts = (int) header_value(HEADER_TS);
    $nonce = (string) header_value(HEADER_NONCE);
    $sigB64 = (string) header_value(HEADER_SIG);

    if ($action === '' || $nonce === '' || $sigB64 === '') {
        throw new AgentError('Missing required signature headers.', 403);
    }
    if (abs(time() - $ts) > TIME_WINDOW) {
        throw new AgentError('Timestamp outside the allowed window.', 403);
    }

    $bodyHash = is_array($body) ? $body['sha256'] : hash('sha256', $body);
    $message = $action . "\n" . $ts . "\n" . $nonce . "\n" . $bodyHash;

    $pub = base64_decode((string) $CONFIG['publicKey'], true);
    $sig = base64_decode($sigB64, true);
    if (
        $pub === false || $sig === false
        || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
    ) {
        throw new AgentError('Invalid signature.', 403);
    }
    if (!sodium_crypto_sign_verify_detached($sig, $message, $pub)) {
        throw new AgentError('Invalid signature.', 403);
    }

    check_nonce_replay($nonce, $ts);
}

/**
 * Best-effort replay protection: stores recent nonces in a state file.
 * If the write fails (read-only FS), it relies on the time window alone.
 */
function check_nonce_replay(string $nonce, int $ts): void
{
    $store = sys_get_temp_dir() . '/psync_nonces';
    $fh = @fopen($store, 'c+');
    if ($fh === false) {
        return; // best-effort
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return;
    }
    $now = time();
    $seen = array();
    rewind($fh);
    while (($line = fgets($fh)) !== false) {
        $parts = explode(' ', trim($line));
        if (count($parts) !== 2) {
            continue;
        }
        if ((int) $parts[1] >= $now - TIME_WINDOW) {
            $seen[$parts[0]] = (int) $parts[1];
        }
    }
    if (isset($seen[$nonce])) {
        flock($fh, LOCK_UN);
        fclose($fh);
        throw new AgentError('Replay detected (nonce already used).', 403);
    }
    $seen[$nonce] = $ts;
    ftruncate($fh, 0);
    rewind($fh);
    foreach ($seen as $n => $t) {
        fwrite($fh, $n . ' ' . $t . "\n");
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}


// ===========================================================================
// Dispatch
// ===========================================================================

/**
 * @param string|array $body
 */
function dispatch(array $CONFIG, string $action, $body): void
{
    switch ($action) {
        case 'capabilities':
            handle_capabilities($CONFIG);
            return;
        case 'list':
            handle_list($CONFIG, json_body($body));
            return;
        case 'hash':
            handle_hash($CONFIG, json_body($body));
            return;
        case 'download':
            handle_download($CONFIG, json_body($body));
            return;
        case 'upload':
            handle_upload($CONFIG, $body);
            return;
        case 'delete':
            handle_delete($CONFIG, json_body($body));
            return;
        default:
            throw new AgentError("Unknown action: '$action'.", 400);
    }
}

/**
 * @param string|array $body
 * @return array
 */
function json_body($body): array
{
    if (is_array($body)) {
        throw new AgentError('Unexpected binary body.', 400);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new AgentError('Invalid JSON body.', 400);
    }
    return $data;
}


// ===========================================================================
// Handler: capabilities
// ===========================================================================

function handle_capabilities(array $CONFIG): void
{
    header('Content-Type: application/json; charset=utf-8');
    $caps = array(
        'protocolVersion'       => (int) $CONFIG['protocolVersion'],
        'serverTime'            => time(),
        'phpVersion'            => PHP_VERSION,
        'postMaxSize'           => ini_bytes((string) ini_get('post_max_size')),
        'uploadMaxFilesize'     => ini_bytes((string) ini_get('upload_max_filesize')),
        'maxFileUploads'        => (int) ini_get('max_file_uploads'),
        'memoryLimit'           => ini_bytes((string) ini_get('memory_limit')),
        'maxExecutionTime'      => isset($CONFIG['_maxExecutionTime'])
            ? (int) $CONFIG['_maxExecutionTime']
            : (int) ini_get('max_execution_time'),
        'setTimeLimitAvailable' => set_time_limit_available(),
        'hashAlgos'             => array_values(array_intersect(array('md5', 'sha1', 'crc32b'), hash_algos())),
        'zlibOutputCompression' => !empty($CONFIG['_zlibOutputCompression']),
    );
    echo json_encode($caps);
}


// ===========================================================================
// Handler: list (phase 1 – fast scan)
// ===========================================================================

/**
 * Streams NDJSON {p: base64(relpath), s: size, m: mtime} in a deterministic
 * order. After a full traversal it sends {"end": true}.
 *
 * Resumability: list is a fast metadata phase and is expected to fit within
 * the limit. If the client does not receive {"end": true} (timeout/crash), it
 * repeats `list` from the start – the traversal is deterministic and
 * idempotent. The expensive part is the `hash` phase, which the client batches
 * itself (resumability matters there).
 */
function handle_list(array $CONFIG, array $req): void
{
    $root = $CONFIG['root'];
    $scope = isset($req['path']) ? (string) $req['path'] : '';
    $base = resolve_scope($root, $scope);

    header('Content-Type: application/x-ndjson; charset=utf-8');

    if ($base === null) {
        emit(array('error' => 'Path outside the allowed scope.'));
        return;
    }
    if (!file_exists($base)) {
        emit(array('end' => true)); // scope does not exist on the server = empty
        return;
    }

    walk_files($root, $base, function (string $rel, $stat): void {
        emit(array('p' => base64_encode($rel), 's' => (int) $stat['size'], 'm' => (int) $stat['mtime']));
    });

    emit(array('end' => true));
}


// ===========================================================================
// Handler: hash (phase 2)
// ===========================================================================

/**
 * Input: {paths: [base64, ...]}. For each path it streams md5 (hash_file).
 * NDJSON output: {p, h} or {p, h:null} if the file is missing/unreadable.
 */
function handle_hash(array $CONFIG, array $req): void
{
    $root = $CONFIG['root'];
    $paths = isset($req['paths']) && is_array($req['paths']) ? $req['paths'] : array();

    header('Content-Type: application/x-ndjson; charset=utf-8');

    foreach ($paths as $p64) {
        $rel = base64_decode((string) $p64, true);
        if ($rel === false) {
            continue;
        }
        $abs = resolve_scope($root, $rel);
        if ($abs === null || !is_file($abs)) {
            emit(array('p' => $p64, 'h' => null));
            continue;
        }
        $h = @hash_file(HASH_ALGO, $abs);
        emit(array('p' => $p64, 'h' => $h === false ? null : $h));
    }
    emit(array('end' => true));
}


// ===========================================================================
// Handler: delete
// ===========================================================================

/**
 * Input: {paths:[base64]}. Deletes files, except those in the protect list
 * (baked in at install time). Second line of defense – the client only deletes
 * after its own check.
 */
function handle_delete(array $CONFIG, array $req): void
{
    $root = $CONFIG['root'];
    $protect = isset($CONFIG['protect']) && is_array($CONFIG['protect']) ? $CONFIG['protect'] : array();
    $paths = isset($req['paths']) && is_array($req['paths']) ? $req['paths'] : array();

    header('Content-Type: application/x-ndjson; charset=utf-8');

    foreach ($paths as $p64) {
        $rel = base64_decode((string) $p64, true);
        if ($rel === false) {
            continue;
        }
        if (path_matches_any($rel, $protect)) {
            emit(array('p' => $p64, 'ok' => false, 'err' => 'protected'));
            continue;
        }
        $abs = resolve_scope($root, $rel);
        if ($abs === null) {
            emit(array('p' => $p64, 'ok' => false, 'err' => 'path outside scope'));
            continue;
        }
        if (!file_exists($abs)) {
            emit(array('p' => $p64, 'ok' => true)); // already gone = goal met
            continue;
        }
        if (is_dir($abs) || is_link($abs)) {
            emit(array('p' => $p64, 'ok' => false, 'err' => 'not a regular file'));
            continue;
        }
        emit(array('p' => $p64, 'ok' => @unlink($abs)));
    }
    emit(array('end' => true));
}

/**
 * Does the relative path match any pattern? (mirrors the client's IgnoreMatcher)
 */
function path_matches_any(string $rel, array $patterns): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    foreach ($patterns as $pattern) {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            continue;
        }
        if (strncmp($pattern, '/', 1) === 0) {
            $p = ltrim($pattern, '/');
            if ($rel === $p || strncmp($rel, $p . '/', strlen($p) + 1) === 0) {
                return true;
            }
            if (fnmatch($p, $rel, FNM_PATHNAME) || fnmatch($p . '/*', $rel, FNM_PATHNAME)) {
                return true;
            }
        } else {
            if (fnmatch($pattern, basename($rel))) {
                return true;
            }
            foreach (explode('/', $rel) as $seg) {
                if (fnmatch($pattern, $seg)) {
                    return true;
                }
            }
        }
    }
    return false;
}


// ===========================================================================
// Handler: download (remote → client, binary framing)
// ===========================================================================

/**
 * Input: {files:[base64], compress:bool, skipExt:[ext]}.
 * Streams a per-file frame: header + (optionally gz) payload.
 * Missing/unreadable files are silently skipped (the client re-requests /
 * reports them).
 */
function handle_download(array $CONFIG, array $req): void
{
    $root = $CONFIG['root'];
    $files = isset($req['files']) && is_array($req['files']) ? $req['files'] : array();
    $compress = !empty($req['compress']);
    $skip = array();
    if (isset($req['skipExt']) && is_array($req['skipExt'])) {
        foreach ($req['skipExt'] as $e) {
            $skip[strtolower((string) $e)] = true;
        }
    }

    header('Content-Type: application/octet-stream');

    foreach ($files as $p64) {
        $rel = base64_decode((string) $p64, true);
        if ($rel === false) {
            continue;
        }
        $abs = resolve_scope($root, $rel);
        if ($abs === null || !is_file($abs)) {
            continue;
        }
        $size = (int) filesize($abs);
        $mtime = (int) filemtime($abs);
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        $gz = $compress && !isset($skip[$ext]);

        if ($gz) {
            $tmp = gz_to_temp($abs, $md5raw, $plen);
            if ($tmp === null) {
                continue;
            }
            echo frame_pack_header($rel, FLAG_GZIP, $mtime, $size, $plen, $md5raw);
            $fh = fopen($tmp, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    $c = fread($fh, CHUNK);
                    if ($c !== false && $c !== '') {
                        echo $c;
                    }
                }
                fclose($fh);
            }
            @unlink($tmp);
        } else {
            $md5raw = md5_file($abs, true);
            if ($md5raw === false) {
                continue;
            }
            echo frame_pack_header($rel, 0, $mtime, $size, $size, $md5raw);
            $fh = fopen($abs, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    $c = fread($fh, CHUNK);
                    if ($c !== false && $c !== '') {
                        echo $c;
                    }
                }
                fclose($fh);
            }
        }
        @flush();
    }
}


// ===========================================================================
// Handler: upload (client → remote, binary framing)
// ===========================================================================

/**
 * The body (already streamed to temp in read_body) contains a sequence of
 * frames. Each file is written atomically (tmp + rename), the source mtime is
 * set, and md5 is verified. Per-file result as NDJSON.
 *
 * @param array $body ['tmp' => path, 'sha256' => hex]
 */
function handle_upload(array $CONFIG, $body): void
{
    if (!is_array($body)) {
        throw new AgentError('Upload expects a binary body.', 400);
    }
    $root = $CONFIG['root'];
    header('Content-Type: application/x-ndjson; charset=utf-8');

    $in = fopen($body['tmp'], 'rb');
    if ($in === false) {
        throw new AgentError('Cannot open the upload body.', 500);
    }

    while (($h = frame_read_header($in)) !== null) {
        $rel = $h['path'];
        $abs = resolve_scope($root, $rel);
        if ($abs === null) {
            skip_bytes($in, $h['payloadLen']);
            emit(array('p' => base64_encode($rel), 'ok' => false, 'err' => 'path outside scope'));
            continue;
        }
        $err = write_upload_file($abs, $in, $h);
        emit(array('p' => base64_encode($rel), 'ok' => $err === null, 'err' => $err));
    }

    fclose($in);
    @unlink($body['tmp']);
    emit(array('end' => true));
}

/**
 * Writes a single file from a frame atomically. Returns null on success,
 * otherwise an error.
 *
 * @param resource $in
 * @param array $h frame header
 * @return string|null
 */
function write_upload_file(string $abs, $in, array $h)
{
    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        skip_bytes($in, $h['payloadLen']);
        return 'cannot create directory';
    }

    $tmp = $abs . '.psync-' . substr(md5($abs . $h['mtime']), 0, 8) . '.tmp';
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        skip_bytes($in, $h['payloadLen']);
        return 'cannot open target temp';
    }

    $ctx = hash_init('md5');
    $gz = ($h['flags'] & FLAG_GZIP) !== 0;
    $inflate = $gz ? inflate_init(ZLIB_ENCODING_GZIP) : null;
    $remaining = $h['payloadLen'];

    while ($remaining > 0) {
        $chunk = fread($in, $remaining < CHUNK ? $remaining : CHUNK);
        if ($chunk === false || $chunk === '') {
            fclose($out);
            @unlink($tmp);
            return 'truncated payload';
        }
        $remaining -= strlen($chunk);
        if ($gz) {
            $chunk = inflate_add($inflate, $chunk, ZLIB_NO_FLUSH);
            if ($chunk === false) {
                fclose($out);
                @unlink($tmp);
                return 'decompression error';
            }
        }
        if ($chunk !== '') {
            hash_update($ctx, $chunk);
            fwrite($out, $chunk);
        }
    }
    if ($gz) {
        $tail = inflate_add($inflate, '', ZLIB_FINISH);
        if ($tail === false) {
            fclose($out);
            @unlink($tmp);
            return 'decompression error (finish)';
        }
        if ($tail !== '') {
            hash_update($ctx, $tail);
            fwrite($out, $tail);
        }
    }
    fclose($out);

    if (hash_final($ctx, true) !== $h['md5']) {
        @unlink($tmp);
        return 'md5 mismatch';
    }
    if (!@rename($tmp, $abs)) {
        @unlink($tmp);
        return 'rename failed';
    }
    @touch($abs, $h['mtime']);
    return null;
}


// ===========================================================================
// Binary framing (must match the client – JakubBoucek\Psync\Protocol\Wire)
// ===========================================================================

function frame_pack_header(string $path, int $flags, int $mtime, int $origSize, int $payloadLen, string $md5raw): string
{
    return pack('N', strlen($path)) . $path
        . pack('C', $flags)
        . pack('J', $mtime)
        . pack('J', $origSize)
        . pack('J', $payloadLen)
        . $md5raw;
}

/**
 * Reads a frame header. Returns null on a clean EOF.
 *
 * @param resource $in
 * @return array|null
 */
function frame_read_header($in)
{
    $lenRaw = stream_read_exact($in, 4, true);
    if ($lenRaw === null) {
        return null;
    }
    $pathLen = unpack('N', $lenRaw)[1];
    $path = $pathLen > 0 ? stream_read_exact($in, $pathLen) : '';
    $fixed = stream_read_exact($in, 41);
    return array(
        'path' => $path,
        'flags' => unpack('C', substr($fixed, 0, 1))[1],
        'mtime' => unpack('J', substr($fixed, 1, 8))[1],
        'origSize' => unpack('J', substr($fixed, 9, 8))[1],
        'payloadLen' => unpack('J', substr($fixed, 17, 8))[1],
        'md5' => substr($fixed, 25, 16),
    );
}

/**
 * @param resource $in
 * @return string|null
 */
function stream_read_exact($in, int $n, bool $allowEof = false)
{
    if ($n === 0) {
        return '';
    }
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($in, $n - strlen($buf));
        if ($chunk === false || $chunk === '') {
            if ($allowEof && $buf === '') {
                return null;
            }
            throw new AgentError('Truncated frame in the upload stream.', 400);
        }
        $buf .= $chunk;
    }
    return $buf;
}

/**
 * @param resource $in
 */
function skip_bytes($in, int $n): void
{
    while ($n > 0) {
        $chunk = fread($in, $n < CHUNK ? $n : CHUNK);
        if ($chunk === false || $chunk === '') {
            return;
        }
        $n -= strlen($chunk);
    }
}

/**
 * Compresses a file into a temporary file (streamed, gzip).
 * Fills $md5raw (md5 of the original, raw) and $plen (compressed size).
 * Returns the path to the temp file or null on error.
 *
 * @param string $md5raw (out)
 * @param int $plen (out)
 * @return string|null
 */
function gz_to_temp(string $abs, &$md5raw, &$plen)
{
    $in = fopen($abs, 'rb');
    $tmp = tempnam(sys_get_temp_dir(), 'psync_dl_');
    if ($in === false || $tmp === false) {
        if ($in !== false) {
            fclose($in);
        }
        return null;
    }
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        fclose($in);
        @unlink($tmp);
        return null;
    }
    $ctx = hash_init('md5');
    $deflate = deflate_init(ZLIB_ENCODING_GZIP);
    while (!feof($in)) {
        $chunk = fread($in, CHUNK);
        if ($chunk === false) {
            break;
        }
        if ($chunk !== '') {
            hash_update($ctx, $chunk);
            fwrite($out, deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
        }
    }
    fwrite($out, deflate_add($deflate, '', ZLIB_FINISH));
    fclose($in);
    fclose($out);

    $md5raw = hash_final($ctx, true);
    $plen = (int) filesize($tmp);
    return $tmp;
}


// ===========================================================================
// File utilities
// ===========================================================================

/**
 * Recursively walks the files under $base (sorted) and calls $cb($relPath, $stat).
 * Does not follow symlinks (protection against loops / escaping the root).
 */
function walk_files(string $root, string $base, callable $cb): void
{
    $rootLen = strlen(rtrim($root, '/')) + 1;
    $stack = array($base);

    while (!empty($stack)) {
        $dir = array_pop($stack);
        $entries = @scandir($dir);
        if ($entries === false) {
            continue;
        }
        sort($entries, SORT_STRING);
        $subdirs = array();
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue; // we ignore symlinks
            }
            if (is_dir($full)) {
                $subdirs[] = $full;
            } elseif (is_file($full)) {
                $stat = @stat($full);
                if ($stat !== false) {
                    $cb(substr($full, $rootLen), $stat);
                }
            }
        }
        // sorted and so they are processed before the next sibling directories
        rsort($subdirs, SORT_STRING);
        foreach ($subdirs as $sd) {
            $stack[] = $sd;
        }
    }
}

/**
 * Sanitization of the client-supplied relative path.
 * Returns an absolute path inside $root, or null on an escape attempt.
 */
function resolve_scope(string $root, string $rel)
{
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '') {
        return rtrim($root, '/');
    }
    // reject ../ before realpath (realpath returns false on a nonexistent path)
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '..' || $seg === '.') {
            return null;
        }
    }
    $rootResolved = realpath($root);
    $rootReal = rtrim($rootResolved !== false ? $rootResolved : $root, '/');
    $candidate = $rootReal . '/' . $rel;

    // If it exists, verify realpath inside the root (defense against symlinks in the path).
    $real = realpath($candidate);
    if ($real !== false) {
        return path_within($real, $rootReal) ? $real : null;
    }

    // Does not exist (typically an upload target) – verify the deepest existing
    // ancestor, so a symlinked parent cannot allow escaping the root.
    $parent = $candidate;
    do {
        $parent = dirname($parent);
    } while ($parent !== '/' && $parent !== '.' && $parent !== '' && !file_exists($parent));

    $parentReal = realpath($parent);
    if ($parentReal !== false && !path_within($parentReal, $rootReal)) {
        return null;
    }
    return $candidate;
}

/** Is $path inside (or equal to) $root? */
function path_within(string $path, string $root): bool
{
    $path = rtrim($path, '/');
    $root = rtrim($root, '/');
    return $path === $root || strncmp($path . '/', $root . '/', strlen($root) + 1) === 0;
}

function ini_bytes(string $val): int
{
    $val = trim($val);
    if ($val === '') {
        return 0;
    }
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int) $val;
    switch ($last) {
        case 'g':
            $num *= 1024;
            // fallthrough
        case 'm':
            $num *= 1024;
            // fallthrough
        case 'k':
            $num *= 1024;
    }
    return $num;
}


// ===========================================================================
// Output
// ===========================================================================

function emit(array $obj): void
{
    echo json_encode($obj) . "\n";
    @flush();
}

function send_error(int $code, string $msg): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/x-ndjson; charset=utf-8');
    }
    echo json_encode(array('error' => $msg, 'code' => $code)) . "\n";
    @flush();
}

function header_value(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    // fallback via getallheaders (some SAPIs)
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
    }
    return null;
}
