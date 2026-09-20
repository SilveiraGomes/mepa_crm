<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Query\Builder;

final class DocumentQueryService
{
    public function __construct(private AcademyRuntime $rt) {}

    public function certificates(int $actor, int $session, int $classId, array $input): array
    {
        return $this->rt->read('certificate.view', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), function () use ($classId, $input): array {
            $q = $this->rt->db->table('certificates as c')->join('enrollments as e', 'e.id', '=', 'c.enrollment_id')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.class_id', $classId);
            if (isset($input['status'])) { $q->where('c.status', $input['status']); }
            return $this->page($q, $input, ['c.public_id', 'c.version', 'c.issued_at', 'c.revoked_at', 'c.status', 'c.lock_version', 'e.public_id as enrollment_public_id', 'p.public_id as person_public_id', 'p.full_name as display_name'], ['name'=>'p.full_name','status'=>'c.status','issued_at'=>'c.issued_at','version'=>'c.version']);
        });
    }

    public function certificate(int $actor, int $session, int $classId, int $certificateId): array
    {
        return $this->rt->read('certificate.view', $actor, $session, fn () => $this->rt->scope->forCertificate($certificateId, null), function (AcademyTarget $target) use ($classId, $certificateId): array {
            if ($target->classId !== $classId) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            return (array) $this->rt->db->table('certificates as c')->join('enrollments as e', 'e.id', '=', 'c.enrollment_id')->join('people as p', 'p.id', '=', 'e.person_id')->join('classes as cl', 'cl.id', '=', 'e.class_id')->where('c.id', $certificateId)
                ->first(['c.public_id', 'c.version', 'c.issued_at', 'c.revoked_at', 'c.status', 'c.lock_version', 'e.public_id as enrollment_public_id', 'p.public_id as person_public_id', 'p.full_name as display_name', 'cl.public_id as class_public_id', 'cl.code as class_code']);
        });
    }

    public function transcripts(int $actor, int $session, int $curriculumId, int $personId, array $input): array
    {
        return $this->rt->read('transcript.view', $actor, $session, fn () => $this->rt->scope->forTranscript($personId, $curriculumId, null), function () use ($personId, $curriculumId, $input): array {
            $q = $this->rt->db->table('transcripts')->where('person_id', $personId)->where('curriculum_id', $curriculumId);
            if (isset($input['status'])) { $q->where('status', $input['status']); }
            return $this->page($q, $input, ['public_id', 'version', 'issued_at', 'status', 'lock_version'], ['status'=>'status','issued_at'=>'issued_at','version'=>'version']);
        });
    }

    public function transcript(int $actor, int $session, int $curriculumId, int $personId, int $transcriptId): array
    {
        return $this->rt->read('transcript.view', $actor, $session, fn () => $this->rt->scope->forTranscriptRecord($transcriptId, null), function (AcademyTarget $target) use ($curriculumId, $personId, $transcriptId): array {
            $row = $target->rows['transcript'];
            if ((int) $row->curriculum_id !== $curriculumId || (int) $row->person_id !== $personId) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            $person = $this->rt->db->table('people')->where('id', $personId)->first(['public_id', 'full_name']);
            $lines = $this->rt->db->table('transcript_lines as tl')->join('enrollments as e', 'e.id', '=', 'tl.enrollment_id')->join('classes as c', 'c.id', '=', 'e.class_id')->where('tl.transcript_id', $transcriptId)
                ->get(['e.public_id as enrollment_public_id', 'c.public_id as class_public_id', 'c.code as class_code', 'tl.final_score', 'tl.result_status'])->map(fn ($r) => (array) $r)->all();
            return ['public_id' => $row->public_id, 'version' => (int) $row->version, 'issued_at' => $row->issued_at, 'status' => $row->status, 'lock_version' => (int) $row->lock_version,
                'person' => ['public_id' => $person->public_id, 'display_name' => $person->full_name], 'lines' => $lines];
        });
    }

    private function page(Builder $q, array $input, array $columns, array $sorts = []): array
    {
        [$page, $perPage] = AcademyInput::page((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 50));
        $total = (clone $q)->count();
        $default = preg_split('/\s+as\s+/i', $columns[0])[0];
        $order = isset($input['sort'], $sorts[$input['sort']]) ? $sorts[$input['sort']] : $default;
        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'items' => $q->orderBy($order, $input['direction'] ?? 'asc')->forPage($page, $perPage)->get($columns)->map(fn ($r) => (array) $r)->all()];
    }
}
