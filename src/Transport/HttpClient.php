<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Transport;

use CurlHandle;
use JakubBoucek\Psync\Console\Reporter;
use JakubBoucek\Psync\Protocol\Protocol;
use JakubBoucek\Psync\Protocol\Signer;
use JakubBoucek\Psync\Protocol\Wire;
use JakubBoucek\Psync\Sync\PathRelativizer;
use RuntimeException;

/**
 * HTTP transport to the server agent. Signs requests and streams NDJSON
 * responses line by line (memory-friendly, resilient to a premature crash).
 */
final class HttpClient
{
    private const string VERSION_MISMATCH = 'Protocol version mismatch: the agent rejected the request. '
        . 'Regenerate the agent with `psync re-install` and re-upload it.';

    /** How much of an HTTP-error body is kept for the exception message. */
    private const int ERROR_BODY_LIMIT = 4096;

    private int $timeOffset = 0;

    /** @param non-empty-string $userAgent */
    public function __construct(
        private readonly string $url,
        private readonly Signer $signer,
        private readonly ?string $expectedScopeRelPath = null,
        private readonly ?Reporter $reporter = null,
        private readonly bool $forceHttp1 = false,
        private readonly string $userAgent = 'psync',
        private readonly bool $insecure = false,
        private readonly ?string $resolve = null,
    ) {
    }

    /**
     * Loads capabilities and at the same time synchronizes the clock with the
     * server (to account for skew).
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        // Warn before the first request, so the reduced security is visible even
        // when the connection itself fails.
        if ($this->insecure) {
            $this->reporter?->warn(
                'TLS certificate verification is disabled (--insecure) — the server identity and responses '
                . 'are not authenticated; use only temporarily (e.g. during a migration).',
            );
        }
        if ($this->resolve !== null) {
            $this->reporter?->warn(sprintf(
                'DNS override is active (--resolve %s) — the agent host connects to a manually pinned '
                . 'address, not what DNS says; remove it once the real DNS points there.',
                $this->resolve,
            ));
        }

        $lines = $this->postJson(Protocol::ACTION_CAPABILITIES, []);
        $caps = $lines[0] ?? null;
        if (!is_array($caps) || !isset($caps['serverTime'])) {
            throw new RuntimeException('Invalid capabilities response.');
        }
        $this->timeOffset = (int) $caps['serverTime'] - time();

        $agentVersion = (int) ($caps['protocolVersion'] ?? 0);
        if ($agentVersion !== Protocol::VERSION) {
            throw new RuntimeException(sprintf(
                'Protocol version mismatch: the agent is v%d but this psync client is v%d. '
                . 'Regenerate the agent with `psync re-install` and re-upload it.',
                $agentVersion,
                Protocol::VERSION,
            ));
        }

        $this->checkScope($caps);
        $this->checkPhpVersion($caps);

        if ($this->forceHttp1) {
            $this->reporter?->log('Forcing HTTP/1.1 for all agent requests.');
        }
        $this->reporter?->log(sprintf(
            'Server: PHP %s, post_max_size %d B, max_execution_time %s s, clock offset %+d s',
            (string) ($caps['phpVersion'] ?? '?'),
            (int) ($caps['postMaxSize'] ?? 0),
            (string) ($caps['maxExecutionTime'] ?? '?'),
            $this->timeOffset,
        ));
        return $caps;
    }

    /**
     * Cross-checks the deployed agent's baked scope against what the config
     * expects, so a layout edit without a re-deploy hard-fails up front instead
     * of the agent later rejecting (or mis-resolving) paths. Skipped when no
     * expected scope was supplied (e.g. a capabilities-only probe).
     *
     * @param array<string, mixed> $caps
     */
    private function checkScope(array $caps): void
    {
        if ($this->expectedScopeRelPath === null) {
            return;
        }

        $reported = (string) ($caps['scopeRelPath'] ?? '');
        if (PathRelativizer::normalize($reported) !== PathRelativizer::normalize($this->expectedScopeRelPath)) {
            throw new RuntimeException(sprintf(
                "Agent scope mismatch: the deployed agent syncs '%s' relative to its own directory, "
                . "but the config expects '%s'. Regenerate the agent with `psync re-install` and re-upload it.",
                $reported,
                $this->expectedScopeRelPath,
            ));
        }

        if (($caps['syncRoot'] ?? null) === null) {
            throw new RuntimeException(sprintf(
                "Agent scope does not resolve on the server: the baked path '%s' points outside the agent's "
                . 'reachable tree. Check agent-dir/sync-root and run `psync re-install`.',
                $reported,
            ));
        }
    }

    /**
     * Warns (does not fail) when the server's PHP is older than what the agent
     * is written for. The agent may still happen to work there — auth and
     * capabilities already succeeded at this point — so the run continues as a
     * best-effort try; the warning explains any weirdness that follows.
     *
     * @param array<string, mixed> $caps
     */
    private function checkPhpVersion(array $caps): void
    {
        $php = $caps['phpVersion'] ?? null;
        if (!is_string($php) || $php === '') {
            return;
        }
        if (version_compare($php, Protocol::AGENT_MIN_PHP, '<')) {
            $this->reporter?->warn(sprintf(
                'Server runs PHP %s, but the psync agent supports PHP %s+ — continuing as a best-effort try, '
                . 'behavior on this version is untested.',
                $php,
                Protocol::AGENT_MIN_PHP,
            ));
        }
    }

    /**
     * Sends a signed JSON request and returns the decoded NDJSON lines.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function postJson(string $action, array $payload): array
    {
        $lines = [];
        $this->streamJson($action, $payload, static function (array $obj) use (&$lines): void {
            $lines[] = $obj;
        });
        return $lines;
    }

    /**
     * Sends a signed JSON request and streams NDJSON lines to the callback.
     * The callback receives the decoded object of each line. An {"error":...}
     * line or a non-zero HTTP code throws an exception.
     *
     * @param array<string, mixed> $payload
     * @param callable(array<string,mixed>):void $onLine
     */
    public function streamJson(string $action, array $payload, callable $onLine): void
    {
        $body = json_encode(['action' => $action] + $payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Cannot encode the request.');
        }
        $headers = $this->signedHeaders($action, $body);
        $headers[] = 'Content-Type: application/json';

        $this->reporter?->debug(sprintf('POST %s (%d B)', $action, strlen($body)));
        $this->reporter?->trace('→ ' . $this->url);
        $t0 = microtime(true);

        $error = null;
        $this->exec($body, $headers, static function (array $obj) use ($onLine, &$error): void {
            if (isset($obj['error'])) {
                $error = (string) $obj['error'];
                return;
            }
            $onLine($obj);
        });

        $this->reporter?->debug(sprintf('  %s done in %d ms', $action, (int) round((microtime(true) - $t0) * 1000)));

        if ($error !== null) {
            throw new RuntimeException("Agent returned an error: $error");
        }
    }

    /**
     * Downloads a binary response (download) into a temporary file and returns
     * its path. The caller is responsible for deleting it. On an HTTP error it
     * throws an exception built from the body head (see httpErrorMessage()).
     *
     * @param array<string, mixed> $payload
     */
    public function downloadToTemp(array $payload): string
    {
        $body = json_encode(['action' => Protocol::ACTION_DOWNLOAD] + $payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Cannot encode the download request.');
        }
        $headers = $this->signedHeaders(Protocol::ACTION_DOWNLOAD, $body);
        $headers[] = 'Content-Type: application/json';

        $fileCount = is_array($payload['files'] ?? null) ? count($payload['files']) : 0;
        $this->reporter?->debug(sprintf('POST download (%d files)', $fileCount));

        $tmp = tempnam(sys_get_temp_dir(), 'psync_dl_');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open the temporary file.');
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FILE => $fh,
        ] + $this->connectionOptions());
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            @unlink($tmp);
            throw new RuntimeException("Connection failed: $curlErr");
        }
        if ($code === 426) {
            @unlink($tmp);
            throw new RuntimeException(self::VERSION_MISMATCH);
        }
        if ($code >= 400) {
            $head = (string) file_get_contents($tmp, false, null, 0, self::ERROR_BODY_LIMIT);
            @unlink($tmp);
            throw new RuntimeException($this->httpErrorMessage($code, $head, $contentType));
        }
        return $tmp;
    }

    /**
     * Sends the binary upload body (X-Psync-Action: upload) and streams NDJSON results.
     *
     * @param callable(array<string,mixed>):void $onLine
     */
    public function uploadBody(string $body, callable $onLine): void
    {
        $headers = $this->signedHeaders(Protocol::ACTION_UPLOAD, $body);
        $headers[] = Protocol::HEADER_ACTION . ': ' . Protocol::ACTION_UPLOAD;
        $headers[] = 'Content-Type: application/octet-stream';

        $this->reporter?->debug(sprintf('POST upload (%d B)', strlen($body)));

        $error = null;
        $this->exec($body, $headers, static function (array $obj) use ($onLine, &$error): void {
            if (isset($obj['error'])) {
                $error = (string) $obj['error'];
                return;
            }
            $onLine($obj);
        });
        if ($error !== null) {
            throw new RuntimeException("Agent returned an error: $error");
        }
    }

    /**
     * @param string|resource $body  the body (string for JSON, resource for a binary upload)
     * @param list<string> $headers
     * @param callable(array<string,mixed>):void $onLine
     */
    private function exec($body, array $headers, callable $onLine): void
    {
        $ch = curl_init($this->url);
        $buffer = '';
        $deliver = static function (string $line) use ($onLine): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $onLine(Wire::parseNdjson($line));
        };

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $ch, string $data) use (&$buffer, $deliver): int {
                // On an error status the body may not come from the agent at all (a hosting
                // error page, a bot-protection challenge) – keep its head for the exception
                // message instead of parsing it as NDJSON.
                if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
                    if (strlen($buffer) < self::ERROR_BODY_LIMIT) {
                        $buffer .= $data;
                    }
                    return strlen($data);
                }
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $deliver(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                }
                return strlen($data);
            },
        ] + $this->connectionOptions());

        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new RuntimeException("Connection failed: $curlErr");
        }
        if ($code === 426) {
            throw new RuntimeException(self::VERSION_MISMATCH);
        }
        if ($code >= 400) {
            throw new RuntimeException($this->httpErrorMessage($code, $buffer, $contentType));
        }
        if ($buffer !== '') {
            $deliver($buffer); // last line without a trailing \n
        }
    }

    /**
     * Message for an HTTP-error response. The agent reports errors as a JSON
     * {"error":...} first line; anything else means the response was produced
     * by the hosting layer (error page, bot protection), not the agent.
     */
    private function httpErrorMessage(int $code, string $bodyHead, string $contentType): string
    {
        $firstLine = strtok($bodyHead, "\n");
        $obj = $firstLine === false ? null : json_decode(trim($firstLine), true);
        if (is_array($obj) && isset($obj['error'])) {
            return "Agent responded with HTTP $code: " . (string) $obj['error'];
        }
        return sprintf(
            'Agent responded with HTTP %d and a non-JSON body%s – the response is probably not from the '
            . 'psync agent (a hosting error page or bot protection intercepted the request).',
            $code,
            $contentType !== '' ? " ($contentType)" : '',
        );
    }

    /**
     * The HTTP version for curl: negotiated (default) or forced HTTP/1.1 — a
     * workaround for hostings whose HTTP/2 layer kills long flushed streaming
     * responses (`HTTP/2 stream … INTERNAL_ERROR`).
     */
    private function httpVersion(): int
    {
        return $this->forceHttp1 ? CURL_HTTP_VERSION_1_1 : CURL_HTTP_VERSION_NONE;
    }

    /**
     * Connection-level curl options shared by every request (streamed and
     * download alike), so a transport tweak can never apply to just one of the
     * two code paths.
     *
     * @return array<int, mixed>
     */
    private function connectionOptions(): array
    {
        $opts = [
            CURLOPT_HTTP_VERSION => $this->httpVersion(),
            CURLOPT_USERAGENT => $this->userAgent,
        ];
        if ($this->insecure) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($this->resolve !== null) {
            $opts[CURLOPT_RESOLVE] = [$this->resolve];
        }
        return $opts;
    }

    /**
     * @return list<string>
     */
    private function signedHeaders(string $action, string $body): array
    {
        $ts = time() + $this->timeOffset;
        $h = [Protocol::HEADER_VERSION . ': ' . Protocol::VERSION];
        foreach ($this->signer->headers($action, $body, $ts) as $k => $v) {
            $h[] = "$k: $v";
        }
        return $h;
    }
}
