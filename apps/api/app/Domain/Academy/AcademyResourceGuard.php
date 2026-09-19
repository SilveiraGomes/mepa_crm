<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Connection;

// Existence of a file or legal document is not the right to use it. Before an Academy object
// (certificate, transcript, instructor assignment) references one, the reference is resolved from the
// database and checked against the academic target the operation was authorized for:
//   - the resource is read by id, never trusted from the payload;
//   - it must be OWNED by one of the organizational units the target resolves to. Those units are the
//     ones the actor's institutional scope was checked against (and is checked again at commit), so
//     authority over the resource is the same, already verified, authority over its owner unit;
//   - a resource of any other unit is OUT_OF_SCOPE whatever its classification says. Ownership is
//     compared before availability, so nothing about a foreign resource (status, purge state) is
//     revealed. An id that does not exist stays a distinct domain error; making the two
//     indistinguishable is the HTTP mapping requirement F-06 (A3), not a domain concern;
//   - a file owned by a department instance is refused as well: Academy authority is evaluated on the
//     UNIT scope only and cannot speak for a department-restricted resource.
// There is no approved global/shared-resource mechanism, so none is granted here. files.classification
// is free text without an approved academic-use catalogue; it is deliberately NOT interpreted (no
// vocabulary is invented), which is why the ownership rule above is the whole boundary.
// The rows are read FOR SHARE so the ownership/availability that was checked cannot change before commit.
final class AcademyResourceGuard
{
    public function __construct(private Connection $db)
    {
    }

    public function file(AcademyTarget $target, int $fileId): object
    {
        $file = $this->db->table('files')->where('id', $fileId)->sharedLock()->first();
        if (!$file) {
            throw new AcademyError(AcademyReason::FILE_NOT_AVAILABLE, ['file_id' => $fileId]);
        }
        $this->assertOwnedByTarget($target, (int) $file->owner_unit_id, 'files');
        if ($file->owner_department_id !== null) {
            throw new AcademyError(AcademyReason::OUT_OF_SCOPE, ['entity' => 'files']);
        }
        if ($file->status !== 'AVAILABLE' || $file->deleted_at !== null || $file->purged_at !== null) {
            throw new AcademyError(AcademyReason::FILE_NOT_AVAILABLE, ['file_id' => $fileId]);
        }
        return $file;
    }

    public function legalDocument(AcademyTarget $target, int $documentId): object
    {
        $document = $this->db->table('legal_documents')->where('id', $documentId)->sharedLock()->first();
        if (!$document) {
            throw new AcademyError(AcademyReason::REFERENCE_NOT_FOUND, ['entity' => 'legal_documents']);
        }
        $this->assertOwnedByTarget($target, (int) $document->owner_unit_id, 'legal_documents');
        return $document;
    }

    private function assertOwnedByTarget(AcademyTarget $target, int $ownerUnit, string $entity): void
    {
        if (!in_array($ownerUnit, $target->unitIds, true)) {
            throw new AcademyError(AcademyReason::OUT_OF_SCOPE, ['entity' => $entity]);
        }
    }
}
