<?php

declare(strict_types=1);

namespace PhpSync\Protocol;

/**
 * Sdílené konstanty protokolu. Agent (PHP 7.4) musí dodržet stejné hodnoty –
 * šablona agenta je drží jako vlastní kopii, tato třída je referenční zdroj.
 */
final class Protocol
{
    /** Verze protokolu – zvyš při nekompatibilní změně wire formátu / podpisu. */
    public const VERSION = 1;

    public const HEADER_TS = 'X-Sync-Ts';
    public const HEADER_NONCE = 'X-Sync-Nonce';
    public const HEADER_SIG = 'X-Sync-Sig';
    public const HEADER_ACTION = 'X-Sync-Action';

    public const ACTION_CAPABILITIES = 'capabilities';
    public const ACTION_LIST = 'list';
    public const ACTION_HASH = 'hash';
    public const ACTION_DOWNLOAD = 'download';
    public const ACTION_UPLOAD = 'upload';
    public const ACTION_DELETE = 'delete';

    /** Maximální povolený rozdíl času klient↔server (s) kvůli replay okну. */
    public const TIME_WINDOW = 300;

    /** Příznak v binárním framu: payload je gzipnutý. */
    public const FLAG_GZIP = 0b0000_0001;

    /** Hashovací algoritmus pro detekci změn (nejde o bezpečnost). */
    public const HASH_ALGO = 'md5';
}
