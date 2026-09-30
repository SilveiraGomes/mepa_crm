<?php

declare(strict_types=1);

namespace App\Domain\Files;

// Optional antivirus adapter (ADR 0019 D03 "Antivírus"). Only used when configured; the V1 never claims that a file
// was scanned when it was not (inspection level STRUCTURAL vs STRUCTURAL+AV). An unavailable configured scanner raises
// SCANNER_UNAVAILABLE: the file stays QUARANTINED, the request gets 503, the Cron retries.
interface FileScanner
{
    /** @return bool true = clean, false = infected; throws FilesError(SCANNER_UNAVAILABLE) when it cannot answer */
    public function clean(string $bytes): bool;
}
