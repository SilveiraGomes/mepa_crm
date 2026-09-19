<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Support\Str;

// Transcripts are assembled from real academic rows and state only facts. Nothing is calculated that
// D-09 has not approved: final_score is always null (no aggregation/weighting without policy),
// result_status is the enrollment's own stored status verbatim (no invented "approved"), and grades
// are listed as stored. A class belongs to a curriculum through its cohort (cohorts.curriculum_id),
// which is what keeps a student's history on the curriculum version they actually followed (C8).
// compile() is read-only and always available; issue() persists the official document and is blocked
// (POLICY_NOT_CONFIGURED) until the transcript state is configured by the domain owners.
final class TranscriptService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function compile(int $actor, int $session, int|string $personRef, int $curriculumId, ?array $claimed = null): array
    {
        return $this->rt->read(
            'transcript.compile',
            $actor,
            $session,
            fn () => $this->rt->scope->forCurriculum($curriculumId, null),
            function (AcademyTarget $target) use ($personRef, $curriculumId) {
                $person = $this->rt->people->resolve($personRef);
                return ['person_id' => $person, 'curriculum_id' => $curriculumId, 'lines' => $this->facts($person, $curriculumId, false)];
            },
            $claimed
        );
    }

    public function issue(int $actor, int $session, int|string $personRef, int $curriculumId, int $fileId, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'transcript.issue',
            $actor,
            $session,
            fn () => $this->rt->scope->forCurriculum($curriculumId, 'share'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($personRef, $curriculumId, $fileId) {
                $person = $this->rt->people->resolve($personRef);
                $this->rt->db->table('people')->where('id', $person)->lockForUpdate()->first();
                $this->rt->people->assertEligible($person);
                $status = $this->rt->policy->initial('transcripts');
                $file = $this->rt->db->table('files')->where('id', $fileId)->sharedLock()->first();
                if (!$file || $file->status !== 'AVAILABLE' || $file->deleted_at !== null || $file->purged_at !== null) {
                    throw new AcademyError(AcademyReason::FILE_NOT_AVAILABLE, ['file_id' => $fileId]);
                }
                $lines = $this->facts($person, $curriculumId, true);
                if ($lines === []) {
                    throw new AcademyError(AcademyReason::TRANSCRIPT_EMPTY, ['curriculum_id' => $curriculumId]);
                }
                $last = $this->rt->db->table('transcripts')->where('person_id', $person)->where('curriculum_id', $curriculumId)->orderByDesc('version')->lockForUpdate()->first();
                $version = $last ? (int) $last->version + 1 : 1;
                $now = AcademyRuntime::ts($this->rt->now());
                $publicId = (string) Str::ulid();
                $id = (int) $this->rt->db->table('transcripts')->insertGetId([
                    'public_id' => $publicId, 'person_id' => $person, 'curriculum_id' => $curriculumId, 'version' => $version, 'issued_at' => $now,
                    'file_id' => $fileId, 'approved_by' => $decision->actor, 'status' => $status, 'created_at' => $now,
                ]);
                foreach ($lines as $line) {
                    $this->rt->db->table('transcript_lines')->insert([
                        'transcript_id' => $id, 'enrollment_id' => $line['enrollment_id'], 'certificate_id' => $line['certificate_id'],
                        'final_score' => null, 'result_status' => $line['status'], 'created_at' => $now,
                    ]);
                }
                $this->rt->audit($decision, $target, 'transcript.issued', 'transcripts', $id, null, ['person_id' => $person, 'curriculum_id' => $curriculumId, 'version' => $version, 'lines' => count($lines)]);
                return ['transcript_id' => $id, 'public_id' => $publicId, 'version' => $version, 'lines' => count($lines)];
            },
            $overrideReason,
            $claimed,
            AcademyReason::STALE_WRITE
        );
    }

    private function facts(int $person, int $curriculumId, bool $lock): array
    {
        $share = fn ($query) => $lock ? $query->sharedLock() : $query;
        $enrollments = $share($this->rt->db->table('enrollments as e')->join('classes as c', 'c.id', '=', 'e.class_id')->join('cohorts as h', 'h.id', '=', 'c.cohort_id')
            ->where('e.person_id', $person)->where('h.curriculum_id', $curriculumId)->orderBy('e.id'))
            ->get(['e.id as enrollment_id', 'e.public_id as enrollment_public_id', 'e.status', 'c.code as class_code']);
        if ($enrollments->isEmpty()) {
            return [];
        }
        $ids = $enrollments->pluck('enrollment_id')->all();
        $grades = [];
        $rows = $share($this->rt->db->table('assessment_attempts as a')->join('grades as g', 'g.attempt_id', '=', 'a.id')->whereIn('a.enrollment_id', $ids)
            ->whereRaw('g.version = (SELECT MAX(g2.version) FROM grades g2 WHERE g2.attempt_id = a.id)')->orderBy('a.id'))
            ->get(['a.enrollment_id', 'a.assessment_id', 'a.attempt_number', 'g.version', 'g.score', 'g.status']);
        foreach ($rows as $row) {
            $grades[(int) $row->enrollment_id][] = ['assessment_id' => (int) $row->assessment_id, 'attempt_number' => (int) $row->attempt_number, 'version' => (int) $row->version, 'score' => (string) $row->score, 'status' => $row->status];
        }
        $certificates = $share($this->rt->db->table('certificates')->whereIn('enrollment_id', $ids)->whereNull('revoked_at'))->pluck('id', 'enrollment_id')->all();
        $publicIds = $share($this->rt->db->table('certificates')->whereIn('enrollment_id', $ids)->whereNull('revoked_at'))->pluck('public_id', 'enrollment_id')->all();
        $lines = [];
        foreach ($enrollments as $e) {
            $lines[] = [
                'enrollment_id' => (int) $e->enrollment_id, 'enrollment_public_id' => $e->enrollment_public_id, 'class_code' => $e->class_code, 'status' => $e->status,
                'grades' => $grades[(int) $e->enrollment_id] ?? [],
                'certificate_id' => isset($certificates[$e->enrollment_id]) ? (int) $certificates[$e->enrollment_id] : null,
                'certificate_public_id' => $publicIds[$e->enrollment_id] ?? null,
                'final_score' => null,
                'final_score_state' => AcademyReason::POLICY_NOT_CONFIGURED,
            ];
        }
        return $lines;
    }
}
