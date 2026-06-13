<?php

declare(strict_types=1);

namespace PhpSync\Transport;

use PhpSync\Protocol\Protocol;
use PhpSync\Protocol\Signer;
use PhpSync\Protocol\Wire;
use RuntimeException;

/**
 * HTTP transport ke server agentovi. Podepisuje requesty a streamuje NDJSON
 * odpovědi po řádcích (paměťově nenáročné, odolné vůči předčasnému pádu).
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
     * Načte capabilities a zároveň synchronizuje čas se serverem (kvůli skew).
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        $lines = $this->postJson(Protocol::ACTION_CAPABILITIES, []);
        $caps = $lines[0] ?? null;
        if (!is_array($caps) || !isset($caps['serverTime'])) {
            throw new RuntimeException('Neplatná odpověď capabilities.');
        }
        $this->timeOffset = (int) $caps['serverTime'] - time();
        return $caps;
    }

    /**
     * Pošle podepsaný JSON request a vrátí dekódované NDJSON řádky.
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
     * Pošle podepsaný JSON request a streamuje NDJSON řádky do callbacku.
     * Callback dostává dekódovaný objekt každého řádku. Řádky {"error":...}
     * i nenulový HTTP kód vyhodí výjimku.
     *
     * @param array<string, mixed> $payload
     * @param callable(array<string,mixed>):void $onLine
     */
    public function streamJson(string $action, array $payload, callable $onLine): void
    {
        $body = json_encode(['action' => $action] + $payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Nelze zakódovat request.');
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
            throw new RuntimeException("Agent vrátil chybu: $error");
        }
    }

    /**
     * Stáhne binární odpověď (download) do dočasného souboru a vrátí jeho cestu.
     * Volající je zodpovědný za smazání. Při HTTP chybě přečte tělo jako NDJSON
     * a vyhodí výjimku.
     *
     * @param array<string, mixed> $payload
     */
    public function downloadToTemp(array $payload): string
    {
        $body = json_encode(['action' => Protocol::ACTION_DOWNLOAD] + $payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Nelze zakódovat download request.');
        }
        $headers = $this->signedHeaders(Protocol::ACTION_DOWNLOAD, $body);
        $headers[] = 'Content-Type: application/json';

        $tmp = tempnam(sys_get_temp_dir(), 'phpsync_dl_');
        if ($tmp === false) {
            throw new RuntimeException('Nelze vytvořit dočasný soubor.');
        }
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Nelze otevřít dočasný soubor.');
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
            throw new RuntimeException("Spojení selhalo: $curlErr");
        }
        if ($code >= 400) {
            $head = (string) file_get_contents($tmp, false, null, 0, 4096);
            @unlink($tmp);
            $msg = $head;
            if (($obj = json_decode(trim(strtok($head, "\n") ?: ''), true)) && isset($obj['error'])) {
                $msg = (string) $obj['error'];
            }
            throw new RuntimeException("Agent odpověděl HTTP $code: $msg");
        }
        return $tmp;
    }

    /**
     * Pošle binární tělo uploadu (X-Sync-Action: upload), streamuje NDJSON výsledky.
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
            throw new RuntimeException("Agent vrátil chybu: $error");
        }
    }

    /**
     * @param string|resource $body  tělo (string pro JSON, resource pro binární upload)
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
            $deliver($buffer); // poslední řádek bez koncového \n
        }
        if ($ok === false) {
            throw new RuntimeException("Spojení selhalo: $curlErr");
        }
        if ($code >= 400) {
            throw new RuntimeException("Agent odpověděl HTTP $code.");
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
