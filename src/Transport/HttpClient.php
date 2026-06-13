<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Transport;

use JakubBoucek\Psync\Protocol\Protocol;
use JakubBoucek\Psync\Protocol\Signer;
use JakubBoucek\Psync\Protocol\Wire;
use RuntimeException;

/**
 * HTTP transport to the server agent. Signs requests and streams NDJSON
 * responses line by line (memory-friendly, resilient to a premature crash).
 */
final class HttpClient
{
    private int $timeOffset = 0;

    public function __construct(
        private readonly string $url,
        private readonly Signer $signer,
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
        $lines = $this->postJson(Protocol::ACTION_CAPABILITIES, []);
        $caps = $lines[0] ?? null;
        if (!is_array($caps) || !isset($caps['serverTime'])) {
            throw new RuntimeException('Invalid capabilities response.');
        }
        $this->timeOffset = (int) $caps['serverTime'] - time();
        return $caps;
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
     * Downloads a binary response (download) into a temporary file and returns
     * its path. The caller is responsible for deleting it. On an HTTP error it
     * reads the body as NDJSON and throws an exception.
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
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            @unlink($tmp);
            throw new RuntimeException("Connection failed: $curlErr");
        }
        if ($code >= 400) {
            $head = (string) file_get_contents($tmp, false, null, 0, 4096);
            @unlink($tmp);
            $msg = $head;
            $firstLine = strtok($head, "\n");
            $obj = $firstLine === false ? null : json_decode(trim($firstLine), true);
            if (is_array($obj) && isset($obj['error'])) {
                $msg = (string) $obj['error'];
            }
            throw new RuntimeException("Agent responded with HTTP $code: $msg");
        }
        return $tmp;
    }

    /**
     * Sends the binary upload body (X-Sync-Action: upload) and streams NDJSON results.
     *
     * @param callable(array<string,mixed>):void $onLine
     */
    public function uploadBody(string $body, callable $onLine): void
    {
        $headers = $this->signedHeaders(Protocol::ACTION_UPLOAD, $body);
        $headers[] = Protocol::HEADER_ACTION . ': ' . Protocol::ACTION_UPLOAD;
        $headers[] = 'Content-Type: application/octet-stream';

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
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$buffer, $deliver): int {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $deliver(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                }
                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($buffer !== '') {
            $deliver($buffer); // last line without a trailing \n
        }
        if ($ok === false) {
            throw new RuntimeException("Connection failed: $curlErr");
        }
        if ($code >= 400) {
            throw new RuntimeException("Agent responded with HTTP $code.");
        }
    }

    /**
     * @return list<string>
     */
    private function signedHeaders(string $action, string $body): array
    {
        $ts = time() + $this->timeOffset;
        $h = [];
        foreach ($this->signer->headers($action, $body, $ts) as $k => $v) {
            $h[] = "$k: $v";
        }
        return $h;
    }
}
