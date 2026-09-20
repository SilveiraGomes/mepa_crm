<?php

declare(strict_types=1);

namespace App\Http\Resources\Academy;

use Illuminate\Http\Resources\Json\JsonResource;

final class AcademyActionResource extends JsonResource
{
    public static $wrap = 'data';

    public function toArray($request): array
    {
        return $this->sanitize((array) $this->resource);
    }

    private function sanitize(array $value): array
    {
        $blocked = ['id', 'person_id', 'enrollment_id', 'instructor_id', 'grade_id', 'attendance_id', 'assessment_id', 'recorded_by', 'graded_by', 'approved_by', 'issued_by', 'file_id', 'source_document_id'];
        foreach ($blocked as $field) {
            unset($value[$field]);
        }
        if (isset($value['public_id'])) {
            foreach (array_keys($value) as $field) {
                if ($field === 'id' || ($field !== 'public_id' && str_ends_with($field, '_id'))) {
                    unset($value[$field]);
                }
            }
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_is_list($item)
                    ? array_map(fn ($row) => is_array($row) ? $this->sanitize($row) : $row, $item)
                    : $this->sanitize($item);
            }
        }
        return $value;
    }
}
