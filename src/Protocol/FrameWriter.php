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
            $gzResult = self::gzToTemp($absFile);
            $header = new FrameHeader($relPath, Protocol::FLAG_GZIP, $mtime, $origSize, $gzResult['len'], $gzResult['md5']);
            fwrite($out, Wire::packFrameHeader($header));
            $pin = fopen($gzResult['tmp'], 'rb');
            if ($pin !== false) {
                stream_copy_to_stream($pin, $out);
                fclose($pin);
            }
            @unlink($gzResult['tmp']);
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
     * Zkomprimuje soubor do dočasného (gzip).
     *
     * @return array{tmp: string, md5: string, len: int}
     */
    private static function gzToTemp(string $absFile): array
    {
        $in = fopen($absFile, 'rb');
        $tmp = tempnam(sys_get_temp_dir(), 'phpsync_gz_');
        if ($in === false || $tmp === false) {
            throw new RuntimeException("Nelze komprimovat: $absFile");
        }
        $out = fopen($tmp, 'wb');
        $deflate = deflate_init(ZLIB_ENCODING_GZIP);
        if ($out === false || $deflate === false) {
            fclose($in);
            throw new RuntimeException("Nelze otevřít gz temp: $tmp");
        }

        $ctx = hash_init('md5');
        while (!feof($in)) {
            $chunk = fread($in, 1 << 16);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                hash_update($ctx, $chunk);
                $compressed = deflate_add($deflate, $chunk, ZLIB_NO_FLUSH);
                if ($compressed !== false) {
                    fwrite($out, $compressed);
                }
            }
        }
        $tail = deflate_add($deflate, '', ZLIB_FINISH);
        if ($tail !== false) {
            fwrite($out, $tail);
        }
        fclose($in);
        fclose($out);

        return [
            'tmp' => $tmp,
            'md5' => hash_final($ctx, true),
            'len' => (int) filesize($tmp),
        ];
    }
}
