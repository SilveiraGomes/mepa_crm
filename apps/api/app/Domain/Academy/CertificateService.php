<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Support\Str;

// ACADEMY_CERTIFY is deliberately not ACADEMY_ASSESS and needs no class assignment (institutional
// homologation); it is scoped to the enrollment's academic unit. Issuance requires, in order: a valid
// completion policy for the class's course version (POLICY_NOT_CONFIGURED), a completed enrollment
// (ENROLLMENT_NOT_COMPLETED), no active certificate already (CERTIFICATE_ALREADY_EXISTS) and an
// AVAILABLE file. The enrollment row is locked exclusively, which serializes issuance per enrollment;
// "at most one non-revoked certificate per enrollment" is an aggregate invariant the schema cannot
// express (only UNIQUE(enrollment_id, version)), so this lock is what makes a duplicate-issue race
// converge. Revocation sets revoked_at and never deletes; a re-issue after revocation is a new version.
// The plaintext token is returned once; only its SHA-256 is stored (token_hash is never public_id).
final class CertificateService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function issue(int $actor, int $session, int $enrollmentId, int $fileId, ?string $reason = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'certificate.issue',
            $actor,
            $session,
            fn () => $this->rt->scope->forEnrollment($enrollmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($enrollmentId, $fileId, $reason) {
                $this->rt->resolver->completionPolicy((int) $target->rows['class']->course_version_id);
                if (!$this->rt->policy->inSet('enrollments', 'completed', (string) $target->rows['enrollment']->status)) {
                    throw new AcademyError(AcademyReason::ENROLLMENT_NOT_COMPLETED, ['enrollment_id' => $enrollmentId]);
                }
                $existing = $this->rt->db->table('certificates')->where('enrollment_id', $enrollmentId)->orderByDesc('version')->lockForUpdate()->get();
                foreach ($existing as $certificate) {
                    if ($certificate->revoked_at === null) {
                        throw new AcademyError(AcademyReason::CERTIFICATE_ALREADY_EXISTS, ['certificate_id' => (int) $certificate->id]);
                    }
                }
                $this->assertFile($fileId);
                $version = $existing->isEmpty() ? 1 : (int) $existing->first()->version + 1;
                $token = 'c_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $publicId = (string) Str::ulid();
                $now = AcademyRuntime::ts($this->rt->now());
                $status = $this->rt->policy->initial('certificates');
                $id = (int) $this->rt->db->table('certificates')->insertGetId([
                    'enrollment_id' => $enrollmentId, 'version' => $version, 'public_id' => $publicId, 'token_hash' => hash('sha256', $token, true),
                    'issued_at' => $now, 'approved_by' => $decision->actor, 'file_id' => $fileId, 'revoked_at' => null, 'status' => $status, 'created_at' => $now,
                ]);
                // The token never reaches audit_logs.
                $this->rt->audit($decision, $target, 'certificate.issued', 'certificates', $id, null, ['enrollment_id' => $enrollmentId, 'version' => $version, 'public_id' => $publicId, 'file_id' => $fileId, 'status' => $status], $reason);
                return ['certificate_id' => $id, 'public_id' => $publicId, 'version' => $version, 'token' => $token];
            },
            $overrideReason,
            $claimed,
            AcademyReason::CERTIFICATE_ALREADY_EXISTS
        );
    }

    public function revoke(int $actor, int $session, int $certificateId, ?string $reason, ?int $expectedLockVersion = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        $reason = AcademyInput::reason($reason);
        $enrollmentId = $this->rt->db->table('certificates')->where('id', $certificateId)->value('enrollment_id');
        if ($enrollmentId === null) {
            throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => 'certificates']);
        }
        return $this->rt->write(
            'certificate.revoke',
            $actor,
            $session,
            fn () => $this->rt->scope->forEnrollment((int) $enrollmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($certificateId, $enrollmentId, $reason, $expectedLockVersion) {
                $row = $this->rt->db->table('certificates')->where('id', $certificateId)->lockForUpdate()->first();
                if (!$row || (int) $row->enrollment_id !== (int) $enrollmentId) {
                    throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'certificates']);
                }
                if ($row->revoked_at !== null) {
                    throw new AcademyError(AcademyReason::CERTIFICATE_ALREADY_REVOKED, ['certificate_id' => $certificateId]);
                }
                $now = AcademyRuntime::ts($this->rt->now());
                $moved = $this->rt->machine->move('certificates', 'certificates', $certificateId, $this->rt->policy->target('certificates', 'revoked'), $expectedLockVersion, ['revoked_at' => max($now, $row->issued_at)]);
                $this->rt->audit($decision, $target, 'certificate.revoked', 'certificates', $certificateId, ['status' => $moved['from'], 'revoked_at' => null], ['status' => $moved['to'], 'revoked_at' => $now], $reason);
                return ['certificate_id' => $certificateId, 'from' => $moved['from'], 'to' => $moved['to']];
            },
            $overrideReason,
            $claimed
        );
    }

    // files.status is closed by the Wave 1 CHECK ck_files_status (QUARANTINED, AVAILABLE, TOMBSTONE, PURGED).
    private function assertFile(int $fileId): void
    {
        $file = $this->rt->db->table('files')->where('id', $fileId)->sharedLock()->first();
        if (!$file || $file->status !== 'AVAILABLE' || $file->deleted_at !== null || $file->purged_at !== null) {
            throw new AcademyError(AcademyReason::FILE_NOT_AVAILABLE, ['file_id' => $fileId]);
        }
    }
}
