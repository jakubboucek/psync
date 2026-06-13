<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Protocol;

/**
 * Shared protocol constants. The agent (PHP 7.4) must honor the same values –
 * the agent template keeps them as its own copy; this class is the reference source.
 */
final class Protocol
{
    /** Protocol version – bump on any incompatible change to the wire format / signature. */
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

    /** Maximum allowed client↔server time difference (s) due to the replay window. */
    public const TIME_WINDOW = 300;

    /** Flag in the binary frame: the payload is gzipped. */
    public const FLAG_GZIP = 0b0000_0001;

    /** Hashing algorithm for change detection (not for security). */
    public const HASH_ALGO = 'md5';
}
