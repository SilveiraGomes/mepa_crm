<?php

declare(strict_types=1);

namespace App\Domain\Files;

// Deterministic reasons of the Documents/Files domain (ADR 0019). TARGET_NOT_FOUND and OUT_OF_SCOPE are rendered as
// the same byte-identical 404 (F-06, D04): unknown, malformed, out-of-scope, above clearance, consumer-unauthorized,
// QUARANTINED, TOMBSTONE (without FILES_MANAGE) and PURGED targets are indistinguishable.
final class FilesReason
{
    public const NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const REASON_REQUIRED = 'REASON_REQUIRED';
    public const CLASSIFICATION_INVALID = 'CLASSIFICATION_INVALID';
    public const CLASSIFICATION_BELOW_FLOOR = 'CLASSIFICATION_BELOW_FLOOR';
    public const CLEARANCE_REQUIRED = 'CLEARANCE_REQUIRED';
    public const FILE_EMPTY = 'FILE_EMPTY';
    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    public const FILE_NAME_INVALID = 'FILE_NAME_INVALID';
    public const FILE_TYPE_NOT_ALLOWED = 'FILE_TYPE_NOT_ALLOWED';
    public const FILE_CONTENT_REJECTED = 'FILE_CONTENT_REJECTED';
    public const FILE_IN_USE = 'FILE_IN_USE';
    public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    public const STALE_WRITE = 'STALE_WRITE';
    public const TRANSITION_NOT_ALLOWED = 'TRANSITION_NOT_ALLOWED';
    public const DOCUMENT_ARCHIVED = 'DOCUMENT_ARCHIVED';
    public const STORAGE_UNAVAILABLE = 'STORAGE_UNAVAILABLE';
    public const CONTENT_UNAVAILABLE = 'CONTENT_UNAVAILABLE';
    public const SCANNER_UNAVAILABLE = 'SCANNER_UNAVAILABLE';
    public const CRYPTO_UNAVAILABLE = 'CRYPTO_UNAVAILABLE';
    public const INTEGRITY_FAILURE = 'INTEGRITY_FAILURE';
    public const CONFIG_MISSING = 'CONFIG_MISSING';
    public const INVARIANT_VIOLATION = 'INVARIANT_VIOLATION';
    public const STORAGE_CONFLICT = 'STORAGE_CONFLICT';

    /** Reasons that are ALWAYS the concealed 404 (F-06). */
    public const CONCEALED = [self::TARGET_NOT_FOUND, self::OUT_OF_SCOPE];
}
