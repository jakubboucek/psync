<?php

declare(strict_types=1);

namespace PhpSync\Protocol;

use RuntimeException;

/**
 * Sestaví binární frame jednoho souboru (pro upload) do dočasného souboru –
 * streamovaně, paměťově nenáročně, s volitelnou per-file gzip kompresí.
 */
final class FrameWriter
{
    /**
     * @return array{tmp: string, size: int} cesta k temp s framem a jeho velikost
     */
    public static function buildFrame(string $relPath, string $absFile, bool $gz): array
    {
        $mtime = (int) @filemtime($absFile);
        $origSize = (int) @filesize($absFile);

        $frameTmp = tempnam(sys_get_temp_dir(), 'phpsync_fr_');
        if ($frameTmp === false) {
            throw new RuntimeException('Nelze vytvořit dočasný soubor framu.');
        }
        $out = fopen($frameTmp, 'wb');
        if ($out === false) {
            throw new RuntimeException("Nelze otevřít temp frame: $frameTmp");
        }

        if ($gz) {
            $payloadTmp = self::gzToTemp($absFile, $md5raw, $payloadLen);
            $header = new FrameHeader($relPath, Protocol::FLAG_GZIP, $mtime, $origSize, $payloadLen, $md5raw);
            fwrite($out, Wire::packFrameHeader($header));
            $pin = fopen($payloadTmp, 'rb');
            if ($pin !== false) {
                stream_copy_to_stream($pin, $out);
                fclose($pin);
            }
            @unlink($payloadTmp);
        } else {
            $md5raw = md5_file($absFile, true);
            if ($md5raw === false) {
                fclose($out);
                @unlink($frameTmp);
                throw new RuntimeException("Nelze přečíst soubor: $absFile");
            }
            $header = new FrameHeader($relPath, 0, $mtime, $origSize, $origSize, $md5raw);
            fwrite($out, Wire::packFrameHeader($header));
            $pin = fopen($absFile, 'rb');
            if ($pin !== false) {
                stream_copy_to_stream($pin, $out);
                fclose($pin);
            }
        }
        fclose($out);

        return ['tmp' => $frameTmp, 'size' => (int) filesize($frameTmp)];
    }

    /**
     * Zkomprimuje soubor do dočasného (gzip), naplní $md5raw a $payloadLen.
     */
    private static function gzToTemp(string $absFile, ?string &$md5raw, ?int &$payloadLen): string
    {
        $in = fopen($absFile, 'rb');
        $tmp = tempnam(sys_get_temp_dir(), 'phpsync_gz_');
        if ($in === false || $tmp === false) {
            throw new RuntimeException("Nelze komprimovat: $absFile");
        }
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException("Nelze otevřít gz temp: $tmp");
        }

        $ctx = hash_init('md5');
        $deflate = deflate_init(ZLIB_ENCODING_GZIP);
        while (!feof($in)) {
            $chunk = fread($in, 1 << 16);
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
        $payloadLen = (int) filesize($tmp);
        return $tmp;
    }
}
