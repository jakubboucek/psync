<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Protocol;

/**
 * Shared protocol constants. The agent (PHP 7.4) must honor the same values –
 * the agent template keeps them as its own copy; this class is the reference source.
 */
final class Protocol
{
    /**
     * Protocol version – bump on any incompatible change to the wire format /
     * signature. The client sends it in the `X-Psync-Version` header on every
     * request; the agent enforces it before auth (except `capabilities`, which
     * stays answerable so the client can discover the agent's version), and the
     * client also hard-fails up front from `capabilities`. A bump therefore
     * forces a re-`install` of the agent.
     */
    public const int VERSION = 1;

    public const string HEADER_VERSION = 'X-Psync-Version';
    public const string HEADER_TS = 'X-Psync-Ts';
    public const string HEADER_NONCE = 'X-Psync-Nonce';
    public const string HEADER_SIG = 'X-Psync-Sig';
    public const string HEADER_ACTION = 'X-Psync-Action';

    public const string ACTION_CAPABILITIES = 'capabilities';
    public const string ACTION_LIST = 'list';
    public const string ACTION_HASH = 'hash';
    public const string ACTION_DOWNLOAD = 'download';
    public const string ACTION_UPLOAD = 'upload';
    public const string ACTION_DELETE = 'delete';

    /** Maximum allowed client↔server time difference (s) due to the replay window. */
    public const int TIME_WINDOW = 300;

    /** Flag in the binary frame: the payload is gzipped. */
    public const int FLAG_GZIP = 0b0000_0001;

    /** Hashing algorithm for change detection (not for security). */
    public const string HASH_ALGO = 'md5';
}
