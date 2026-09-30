<?php

declare(strict_types=1);

namespace App\Domain\Files;

use RuntimeException;
use Throwable;

// `reason` is one of FilesReason. `context` carries codes and internal ids for server logs only; it never carries
// file content, original names, document titles, checksums, storage keys, paths, DEK/KEK or ciphertext, and it never
// leaves the server. `details` is the small, explicitly safe payload an HTTP error may carry (a rejection code and the
// uploader's own tombstone public_id).
final class FilesError extends RuntimeException
{
    public function __construct(public string $reason, public array $context = [], ?Throwable $previous = null, public array $details = [])
    {
        parent::__construct($reason, 0, $previous);
    }
}
