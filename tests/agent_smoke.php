<?php

declare(strict_types=1);

/**
 * Integration smoke test for the agent. Two modes:
 *
 *   php tests/agent_smoke.php render <dir>           – renders the agent + a sample tree
 *                                                      into <dir>, prints the private key to STDOUT
 *   php tests/agent_smoke.php check <url> <privkey>  – runs signed requests against a running agent
 *
 * The rendering here is a simplified copy of what `install` will do (phase 4).
 */

use JakubBoucek\Psync\Install\AgentBuilder;
use JakubBoucek\Psync\Protocol\Protocol;
use JakubBoucek\Psync\Protocol\Signer;
use JakubBoucek\Psync\Protocol\Wire;

require __DIR__ . '/../vendor/autoload.php';

$mode = $argv[1] ?? '';

if ($mode === 'render') {
    $dir = $argv[2] ?? '';
    if ($dir === '') {
        fwrite(STDERR, "Missing target directory.\n");
        exit(2);
    }
    @mkdir($dir . '/sub', 0777, true);
    @mkdir($dir . '/empty', 0777, true); // empty directory – listed as a t='d' entry
    file_put_contents($dir . '/a.txt', "alpha\n");
    file_put_contents($dir . '/sub/b.txt', "beta beta\n");
    // name with diacritics and a space – tests base64 paths (non-UTF8/Windows-1250
    // names cannot be created on macOS APFS, those are covered by protocol_test.php)
    file_put_contents($dir . '/Žluťoučký kůň.txt', "weird\n");

    $pair = Signer::generateKeyPair();
    // Agent sits at the root of the sample tree, so its scope is empty (= __DIR__).
    $agent = new AgentBuilder()->build($pair['public'], '', []);
    file_put_contents($dir . '/agent.php', $agent);

    echo $pair['private'] . "\n";
    exit(0);
}

if ($mode === 'check') {
    $url = $argv[2] ?? '';
    $priv = $argv[3] ?? '';
    if ($url === '' || $priv === '') {
        fwrite(STDERR, "Usage: check <url> <privkey>\n");
        exit(2);
    }
    $signer = new Signer($priv);
    $failed = 0;
    $assert = static function (bool $cond, string $msg) use (&$failed): void {
        echo($cond ? "  ✓ " : "  ✗ ") . $msg . "\n";
        if (!$cond) {
            $failed++;
        }
    };

    /** Sends a signed JSON request, returns [httpCode, rawBody]. */
    $call = static function (string $action, array $payload, ?Signer $s, ?string $forceSig = null, bool $omitVersion = false) use ($url): array {
        $body = json_encode(array_merge(['action' => $action], $payload));
        $headers = $s ? $s->headers($action, $body) : [];
        if ($forceSig !== null) {
            $headers[Protocol::HEADER_SIG] = $forceSig;
        }
        $h = $omitVersion ? [] : [Protocol::HEADER_VERSION . ': ' . Protocol::VERSION];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        $h[] = 'Content-Type: application/json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string) $resp];
    };

    echo "capabilities:\n";
    [$code, $resp] = $call('capabilities', [], $signer);
    $caps = json_decode($resp, true);
    $assert($code === 200, "HTTP 200 (got $code)");
    $assert(is_array($caps) && ($caps['protocolVersion'] ?? null) === Protocol::VERSION, 'protocolVersion matches');
    $assert(($caps['postMaxSize'] ?? 0) > 0 && ($caps['memoryLimit'] ?? 0) !== 0, 'limits filled in');
    $assert(in_array('md5', $caps['hashAlgos'] ?? [], true), 'md5 available');

    echo "auth:\n";
    [$code] = $call('capabilities', [], $signer, 'AAAA'); // forged signature
    $assert($code === 403, "forged signature → 403 (got $code)");
    [$code] = $call('capabilities', [], null); // no signature
    $assert($code === 403, "no signature → 403 (got $code)");

    echo "protocol version:\n";
    [$code] = $call('list', ['path' => ''], $signer, null, true); // omit X-Psync-Version
    $assert($code === 426, "list without X-Psync-Version → 426 (got $code)");
    [$code] = $call('capabilities', [], $signer, null, true); // capabilities is exempt
    $assert($code === 200, "capabilities exempt from version check → 200 (got $code)");

    echo "list:\n";
    [$code, $resp] = $call('list', ['path' => ''], $signer);
    $lines = array_filter(explode("\n", trim($resp)));
    $files = [];
    $end = false;
    foreach ($lines as $ln) {
        $o = Wire::parseNdjson($ln);
        if (($o['end'] ?? false) === true) {
            $end = true;
        } elseif (isset($o['p'])) {
            $files[Wire::decPath($o['p'])] = $o;
        }
    }
    $assert($end, 'list ends with {"end":true}');
    $assert(isset($files['a.txt']) && $files['a.txt']['s'] === 6, 'a.txt with size');
    $assert(isset($files['sub/b.txt']), 'recursion into sub/');
    $assert(isset($files['Žluťoučký kůň.txt']), 'name with diacritics/space survives (base64)');
    // Directories are first-class entries: t='d'; regular files omit 't'.
    $assert(isset($files['empty']) && ($files['empty']['t'] ?? null) === 'd', "empty dir listed with t='d'");
    $assert(isset($files['sub']) && ($files['sub']['t'] ?? null) === 'd', "sub/ listed as directory");
    $assert(!array_key_exists('t', $files['a.txt']), "regular file omits 't'");
    // Self-protection: the agent must never list its own file, so it can never
    // appear in the compare output (and thus never be transferred or deleted).
    $assert(!isset($files['agent.php']), 'agent file is NOT listed (self-protection)');

    echo "list scope + traversal guard:\n";
    [, $resp] = $call('list', ['path' => 'sub'], $signer);
    $scoped = array_filter(explode("\n", trim($resp)), static fn($l) => strpos($l, '"p"') !== false);
    $assert(count($scoped) === 1, 'scope sub/ returns only 1 file');
    [, $resp] = $call('list', ['path' => '../../etc'], $signer);
    $errLine = false;
    $leaked = false;
    foreach (array_filter(explode("\n", trim($resp))) as $ln) {
        $o = Wire::parseNdjson($ln);
        if (isset($o['error'])) {
            $errLine = true;
        }
        if (isset($o['p'])) {
            $leaked = true;
        }
    }
    $assert($errLine && !$leaked, 'path traversal rejected (error, no files)');

    echo "hash:\n";
    $p64 = Wire::encPath('a.txt');
    [$code, $resp] = $call('hash', ['paths' => [$p64, Wire::encPath('neexistuje.txt')]], $signer);
    $hashes = [];
    foreach (array_filter(explode("\n", trim($resp))) as $ln) {
        $o = Wire::parseNdjson($ln);
        if (isset($o['p'])) {
            $hashes[Wire::decPath($o['p'])] = $o['h'];
        }
    }
    $assert(($hashes['a.txt'] ?? '') === md5("alpha\n"), 'md5 a.txt matches');
    $assert(array_key_exists('neexistuje.txt', $hashes) && $hashes['neexistuje.txt'] === null, 'missing file → h:null');

    echo "mkdir + delete (directory round-trip):\n";
    $parse = static function (string $resp): array {
        $out = [];
        foreach (array_filter(explode("\n", trim($resp))) as $ln) {
            $o = Wire::parseNdjson($ln);
            if (isset($o['p'])) {
                $out[Wire::decPath($o['p'])] = $o;
            }
        }
        return $out;
    };
    $d64 = Wire::encPath('smoke_newdir');
    [, $resp] = $call('mkdir', ['paths' => [$d64]], $signer);
    $r = $parse($resp);
    $assert(($r['smoke_newdir']['ok'] ?? false) === true, 'mkdir creates a new directory');
    [, $resp] = $call('delete', ['paths' => [['p' => $d64, 't' => 'd']]], $signer);
    $r = $parse($resp);
    $assert(($r['smoke_newdir']['ok'] ?? false) === true, 'delete removes the empty directory (rmdir)');

    echo "delete is strict about the declared type:\n";
    // a.txt is a file: requesting it as a directory must fail and leave it intact.
    [, $resp] = $call('delete', ['paths' => [['p' => Wire::encPath('a.txt'), 't' => 'd']]], $signer);
    $r = $parse($resp);
    $assert(($r['a.txt']['ok'] ?? null) === false && ($r['a.txt']['err'] ?? '') === 'not a directory', 'file requested as dir → not a directory');
    // sub is a directory: requesting it as a file must fail and leave it intact.
    [, $resp] = $call('delete', ['paths' => [['p' => Wire::encPath('sub')]]], $signer);
    $r = $parse($resp);
    $assert(($r['sub']['ok'] ?? null) === false && ($r['sub']['err'] ?? '') === 'not a regular file', 'dir requested as file → not a regular file');
    // An unknown declared type is rejected, not silently treated as a file.
    [, $resp] = $call('delete', ['paths' => [['p' => Wire::encPath('a.txt'), 't' => 'x']]], $signer);
    $r = $parse($resp);
    $assert(($r['a.txt']['ok'] ?? null) === false && ($r['a.txt']['err'] ?? '') === 'unsupported entry type', 'unknown declared type → rejected');

    echo "self-protection (agent refuses to touch its own file):\n";
    // delete: write = loud per-file refusal, agent.php stays on disk.
    [, $resp] = $call('delete', ['paths' => [['p' => Wire::encPath('agent.php')]]], $signer);
    $r = $parse($resp);
    $assert(
        ($r['agent.php']['ok'] ?? null) === false && ($r['agent.php']['err'] ?? '') === 'refusing to modify the psync agent',
        'delete of the agent → refused',
    );
    // hash: read = skipped, reported as missing (h:null).
    [, $resp] = $call('hash', ['paths' => [Wire::encPath('agent.php')]], $signer);
    $r = $parse($resp);
    $assert(array_key_exists('agent.php', $r) && $r['agent.php']['h'] === null, 'hash of the agent → h:null (skipped)');

    echo $failed === 0 ? "\nAGENT OK\n" : "\nFAILED: $failed\n";
    exit($failed === 0 ? 0 : 1);
}

fwrite(STDERR, "Unknown mode. Use 'render' or 'check'.\n");
exit(2);
