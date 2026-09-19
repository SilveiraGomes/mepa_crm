<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Connection;

// Single resolution point for academic policy (D-09). Policy is DATA read from the schema's
// configurable columns (course_versions.completion_policy_metadata); no threshold, scale, weight,
// attempt limit or pass mark is written in code. Anything absent or malformed is
// POLICY_NOT_CONFIGURED: never a default, never an automatic approval, never an automatic failure.
//
// completion_policy_metadata grammar (structure only; every value comes from the stored JSON):
//   {"criteria": [
//      {"type": "ADMINISTRATIVE_APPROVAL"},
//      {"type": "ATTENDANCE_MIN_RATIO", "min_ratio": "<decimal in [0,1]>"},
//      {"type": "ASSESSMENT_MIN_SCORE", "assessment_id": <int>, "min_score": "<decimal>", "attempt_selection": "LATEST"|"BEST"}
//   ]}
// Unknown criterion types fail closed. A grade only counts once it is in the configured 'final' set.
final class AcademicPolicyResolver
{
    public function __construct(private Connection $db, private AcademyPolicy $policy)
    {
    }

    public function completionPolicy(int $courseVersionId): array
    {
        $raw = $this->db->table('course_versions')->where('id', $courseVersionId)->sharedLock()->value('completion_policy_metadata');
        $policy = $raw === null ? null : json_decode((string) $raw, true);
        if (!is_array($policy) || !is_array($policy['criteria'] ?? null) || $policy['criteria'] === []) {
            throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['missing' => 'completion_policy_metadata']);
        }
        foreach ($policy['criteria'] as $i => $criterion) {
            if (!$this->validCriterion($criterion)) {
                throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['invalid' => 'completion_policy_metadata.criteria.' . $i]);
            }
        }
        return $policy;
    }

    private function validCriterion(mixed $c): bool
    {
        if (!is_array($c) || !is_string($c['type'] ?? null)) {
            return false;
        }
        $decimal = static fn ($v) => is_string($v) && preg_match('/^\d{1,5}(\.\d{1,4})?$/', $v) === 1;
        return match ($c['type']) {
            'ADMINISTRATIVE_APPROVAL' => true,
            'ATTENDANCE_MIN_RATIO' => is_string($c['min_ratio'] ?? null) && preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', $c['min_ratio']) === 1,
            'ASSESSMENT_MIN_SCORE' => is_int($c['assessment_id'] ?? null) && $c['assessment_id'] > 0 && $decimal($c['min_score'] ?? null) && in_array($c['attempt_selection'] ?? null, ['LATEST', 'BEST'], true),
            default => false,
        };
    }

    // Returns [['type' => ..., 'met' => bool], ...]; completion is allowed only when every criterion is met.
    public function evaluateCompletion(object $enrollment, object $class, array $policy): array
    {
        $results = [];
        foreach ($policy['criteria'] as $c) {
            $results[] = ['type' => $c['type'], 'met' => match ($c['type']) {
                'ADMINISTRATIVE_APPROVAL' => true,
                'ATTENDANCE_MIN_RATIO' => $this->attendanceMet((int) $enrollment->id, (int) $class->id, $c['min_ratio']),
                'ASSESSMENT_MIN_SCORE' => $this->assessmentMet((int) $enrollment->id, (int) $class->course_version_id, $c),
            }];
        }
        return $results;
    }

    private function attendanceMet(int $enrollment, int $class, string $minRatio): bool
    {
        $attendable = $this->policy->set('class_sessions', 'attendable');
        $attended = $this->policy->set('academic_attendance', 'attended');
        $total = (int) $this->db->table('class_sessions')->where('class_id', $class)->whereIn('status', $attendable)->sharedLock()->count();
        if ($total === 0) {
            return false;
        }
        $hits = (int) $this->db->table('academic_attendance as a')->join('class_sessions as s', 's.id', '=', 'a.class_session_id')
            ->where('a.enrollment_id', $enrollment)->where('s.class_id', $class)->whereIn('s.status', $attendable)->whereIn('a.status', $attended)
            ->sharedLock()->count();
        // hits >= min_ratio * total, exact DECIMAL arithmetic in the database (no float rounding).
        return (bool) $this->db->selectOne('SELECT CAST(? AS DECIMAL(20,6)) >= (CAST(? AS DECIMAL(20,6)) * ?) AS ok', [$hits, $minRatio, $total])->ok;
    }

    private function assessmentMet(int $enrollment, int $courseVersion, array $c): bool
    {
        $assessment = $this->db->table('assessments')->where('id', $c['assessment_id'])->sharedLock()->first();
        if (!$assessment || (int) $assessment->course_version_id !== $courseVersion) {
            throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['invalid' => 'completion_policy_metadata.assessment_scope']);
        }
        $final = $this->policy->set('grades', 'final');
        $attempts = $this->db->table('assessment_attempts')->where('assessment_id', $assessment->id)->where('enrollment_id', $enrollment)->orderBy('attempt_number')->sharedLock()->get();
        $scored = [];
        foreach ($attempts as $attempt) {
            $grade = $this->db->table('grades')->where('attempt_id', $attempt->id)->orderByDesc('version')->sharedLock()->first();
            $scored[] = $grade && in_array($grade->status, $final, true) ? (string) $grade->score : null;
        }
        if ($c['attempt_selection'] === 'LATEST') {
            $last = $scored === [] ? null : $scored[array_key_last($scored)];
            return $last !== null && $this->decimalGte($last, $c['min_score']);
        }
        foreach ($scored as $score) {
            if ($score !== null && $this->decimalGte($score, $c['min_score'])) {
                return true;
            }
        }
        return false;
    }

    public function decimalGte(string $a, string $b): bool
    {
        return (bool) $this->db->selectOne('SELECT CAST(? AS DECIMAL(20,6)) >= CAST(? AS DECIMAL(20,6)) AS ok', [$a, $b])->ok;
    }
}
