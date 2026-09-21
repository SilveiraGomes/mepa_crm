<?php

declare(strict_types=1);

namespace App\Http\Resources\Academy;

use Illuminate\Http\Resources\Json\JsonResource;

final class AcademyReadResource extends JsonResource
{
    public static $wrap = 'data';

    private const FIELDS = [
        'id', 'public_id', 'code', 'name', 'display_name', 'status', 'version', 'sequence', 'required', 'published_at',
        'starts_at', 'ends_at', 'issued_at', 'revoked_at', 'enrolled_at', 'submitted_at', 'completed_at', 'verified_at',
        'capacity', 'lock_version', 'max_score', 'pass_score', 'max_attempts', 'weight', 'attempt_number', 'final_score',
        'completion_ratio', 'watched_seconds', 'kind', 'provider', 'external_url', 'duration_seconds', 'lesson_name',
        'academic_unit_code', 'academic_unit_name', 'organizational_unit_public_id', 'program_public_id', 'program_code',
        'program_name', 'course_public_id', 'course_code', 'course_name', 'course_version_id', 'course_version', 'cohort_public_id', 'cohort_name',
        'location_public_id', 'location_name', 'class_public_id', 'class_code', 'enrollment_public_id', 'enrollment_status',
        'person_public_id', 'assessment_public_id', 'assessment_name', 'event_session_id', 'result_status',
        'course_count', 'module_count', 'lesson_count', 'resource_count', 'lessons', 'resources', 'person', 'lines',
        'file_name', 'media_type',
    ];

    public function toArray($request): array { return $this->project((array) $this->resource); }

    private function project(array $row): array
    {
        $safe = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === null) { continue; }
            $value = $row[$field];
            if (is_array($value)) {
                $value = array_is_list($value) ? array_map(fn ($v) => is_array($v) ? $this->project($v) : $v, $value) : $this->project($value);
            }
            $safe[$field] = $value;
        }
        return $safe;
    }
}
