<?php

declare(strict_types=1);

namespace App\Domain\Files;

// ADR 0019 D01: closed, explicitly ordered classification vocabulary of files (application code, no ENUM, no CHECK).
//
//   INTERNAL (1) < RESTRICTED (2) < CONFIDENTIAL (3) < HIGHLY_SENSITIVE (4)
//
// FAIL CLOSED: any other value (lower case, legacy free text, PUBLIC, empty) is UNKNOWN. rank() refuses it, a file
// whose stored classification is unknown is never visible, downloadable or attachable (it matches no allowed set),
// and an unknown requested value is a 422, never silently mapped to INTERNAL/RESTRICTED. Only an ABSENT request value
// takes the upload default RESTRICTED.
final class FileClassification
{
    public const INTERNAL = 'INTERNAL';
    public const RESTRICTED = 'RESTRICTED';
    public const CONFIDENTIAL = 'CONFIDENTIAL';
    public const HIGHLY_SENSITIVE = 'HIGHLY_SENSITIVE';

    /** Explicit ordering. */
    public const ORDER = [self::INTERNAL => 1, self::RESTRICTED => 2, self::CONFIDENTIAL => 3, self::HIGHLY_SENSITIVE => 4];
    public const DEFAULT_UPLOAD = self::RESTRICTED;
    /** Clearance given by any operation permission in scope (D01 "Clearance"). */
    public const BASE_CLEARANCE = self::RESTRICTED;

    public const LABELS = [
        self::INTERNAL => 'Interno',
        self::RESTRICTED => 'Restrito',
        self::CONFIDENTIAL => 'Confidencial',
        self::HIGHLY_SENSITIVE => 'Altamente sensível',
    ];

    /**
     * D01 consumer floors: a referenced file never stays below the floor of the consumer that references it.
     * document_versions, resources, credential_templates and import_batches add no floor. Minors (child_profiles) are
     * raised to HIGHLY_SENSITIVE by FilesConsumers::floor().
     */
    public const FLOORS = [
        'person_documents.file_id' => self::HIGHLY_SENSITIVE,
        'person_files.file_id' => self::CONFIDENTIAL,
        'credentials.render_file_id' => self::CONFIDENTIAL,
        'certificates.file_id' => self::CONFIDENTIAL,
        'transcripts.file_id' => self::CONFIDENTIAL,
    ];

    public static function isKnown(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, self::ORDER);
    }

    /** Rank of a KNOWN classification; unknown values fail closed. */
    public static function rank(mixed $code): int
    {
        if (!self::isKnown($code)) {
            throw new FilesError(FilesReason::CLASSIFICATION_INVALID, ['reason' => 'unknown_classification']);
        }
        return self::ORDER[$code];
    }

    /** A classification requested by a client: absent => RESTRICTED; anything else must be an exact known code. */
    public static function requested(mixed $input): string
    {
        if ($input === null) {
            return self::DEFAULT_UPLOAD;
        }
        if (!self::isKnown($input)) {
            throw new FilesError(FilesReason::CLASSIFICATION_INVALID, ['field' => 'classification']);
        }
        return $input;
    }

    public static function fromRank(int $rank): string
    {
        $code = array_search($rank, self::ORDER, true);
        if ($code === false) {
            throw new FilesError(FilesReason::CLASSIFICATION_INVALID, ['reason' => 'unknown_rank']);
        }
        return $code;
    }

    /** True only when $classification is KNOWN and not above the clearance rank. */
    public static function allows(int $clearance, mixed $classification): bool
    {
        return self::isKnown($classification) && self::ORDER[$classification] <= $clearance;
    }

    /** @return list<string> the known codes a clearance rank may see (SQL IN list; unknown values never match). */
    public static function allowedFor(int $clearance): array
    {
        return array_keys(array_filter(self::ORDER, fn (int $rank): bool => $rank <= $clearance));
    }

    public static function max(string ...$codes): string
    {
        $rank = 0;
        foreach ($codes as $code) {
            $rank = max($rank, self::rank($code));
        }
        return self::fromRank(max(1, $rank));
    }
}
