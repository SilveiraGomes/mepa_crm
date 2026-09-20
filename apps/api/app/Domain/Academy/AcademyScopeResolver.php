<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Connection;

// Scope provenance: derives the authorization target from persisted rows. A request may name an
// entity (a class, an attempt, ...) but every organizational unit and class that authority is
// checked against is read from the database here, never taken from the payload.
//
// $lock applies to the ROOT entity only ('update' = FOR UPDATE serialization anchor, 'share' =
// FOR SHARE, null = plain read for read-only paths); ancestors are always share-locked inside a
// transaction so they cannot change underneath the operation.
//
// Locks inside this resolver are taken PARENT-FIRST: academic_unit -> class -> enrollment -> entity
// (attempt, session, certificate, ...). Operations that resolve their target through it and then
// touch the same enrollment (a grade write and a new attempt, say) therefore lock in the same
// direction. This is NOT a global lock order: services that also lock a Person row (enrollment
// creation) or the safety cover lock people and enrollments in the opposite order, which InnoDB
// resolves by deadlock detection and the runtime's retry (A2R-05, tracked, non-blocking).
// The ancestors are found with a plain peek, locked, and the root is then locked
// and re-verified against the peeked parent (a parent that moved in between is STORAGE_CONFLICT).
final class AcademyScopeResolver
{
    public function __construct(private Connection $db)
    {
    }

    private function find(string $table, int $id, ?string $lock): object
    {
        $query = $this->db->table($table)->where('id', $id);
        $query = match ($lock) {
            'update' => $query->lockForUpdate(),
            'share' => $query->sharedLock(),
            default => $query,
        };
        $row = $query->first();
        if (!$row) {
            throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        return $row;
    }

    private function ancestor(?string $lock): ?string
    {
        return $lock === null ? null : 'share';
    }

    private function shared($query, ?string $lock)
    {
        return $lock === null ? $query : $query->sharedLock();
    }

    // Locks the root after its ancestors and proves the parent did not move while we waited.
    private function root(string $table, int $id, string $parentColumn, int $expectedParent, ?string $lock): object
    {
        $row = $this->find($table, $id, $lock);
        if ((int) $row->{$parentColumn} !== $expectedParent) {
            throw new AcademyError(AcademyReason::STORAGE_CONFLICT, ['entity' => $table, 'reason' => 'parent_changed']);
        }
        return $row;
    }

    public function forAcademicUnit(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $unit = $this->find('academic_units', $id, $lock);
        return new AcademyTarget([(int) $unit->unit_id], null, (int) $unit->id, ['academic_unit' => $unit]);
    }

    public function forClass(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('classes', $id, null);
        $unit = $this->find('academic_units', (int) $peek->academic_unit_id, $this->ancestor($lock));
        $class = $lock === null ? $peek : $this->root('classes', $id, 'academic_unit_id', (int) $unit->id, $lock);
        return new AcademyTarget([(int) $unit->unit_id], (int) $class->id, (int) $unit->id, ['class' => $class, 'academic_unit' => $unit]);
    }

    public function forEnrollment(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('enrollments', $id, null);
        $target = $this->forClass((int) $peek->class_id, $this->ancestor($lock));
        $target->rows['enrollment'] = $lock === null ? $peek : $this->root('enrollments', $id, 'class_id', (int) $target->classId, $lock);
        return $target;
    }

    public function forSession(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('class_sessions', $id, null);
        $target = $this->forClass((int) $peek->class_id, $this->ancestor($lock));
        $target->rows['session'] = $lock === null ? $peek : $this->root('class_sessions', $id, 'class_id', (int) $target->classId, $lock);
        return $target;
    }

    public function forAttempt(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('assessment_attempts', $id, null);
        $target = $this->forEnrollment((int) $peek->enrollment_id, $this->ancestor($lock));
        $target->rows['attempt'] = $lock === null ? $peek : $this->root('assessment_attempts', $id, 'enrollment_id', (int) $target->rows['enrollment']->id, $lock);
        return $target;
    }

    public function forCertificate(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('certificates', $id, null);
        $target = $this->forEnrollment((int) $peek->enrollment_id, $this->ancestor($lock));
        $target->rows['certificate'] = $lock === null ? $peek : $this->root('certificates', $id, 'enrollment_id', (int) $target->rows['enrollment']->id, $lock);
        return $target;
    }

    public function forProgram(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('programs', $id, null);
        $unit = $this->find('academic_units', (int) $peek->academic_unit_id, $this->ancestor($lock));
        $program = $lock === null ? $peek : $this->root('programs', $id, 'academic_unit_id', (int) $unit->id, $lock);
        return new AcademyTarget([(int) $unit->unit_id], null, (int) $unit->id, ['program' => $program, 'academic_unit' => $unit]);
    }

    public function forCurriculum(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('curricula', $id, null);
        $target = $this->forProgram((int) $peek->program_id, $this->ancestor($lock));
        $target->rows['curriculum'] = $lock === null ? $peek : $this->root('curricula', $id, 'program_id', (int) $target->rows['program']->id, $lock);
        return $target;
    }

    public function forCohort(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('cohorts', $id, null);
        $unit = $this->find('academic_units', (int) $peek->academic_unit_id, $this->ancestor($lock));
        $cohort = $lock === null ? $peek : $this->root('cohorts', $id, 'academic_unit_id', (int) $unit->id, $lock);
        return new AcademyTarget([(int) $unit->unit_id], null, (int) $unit->id, ['cohort' => $cohort, 'academic_unit' => $unit]);
    }

    public function forCourse(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $course = $this->find('courses', $id, $lock);
        $viaClasses = $this->db->table('course_versions as cv')->join('classes as c', 'c.course_version_id', '=', 'cv.id')
            ->join('academic_units as au', 'au.id', '=', 'c.academic_unit_id')->where('cv.course_id', $id)->distinct()->pluck('au.unit_id')->all();
        $viaCurricula = $this->db->table('curriculum_courses as cc')->join('curricula as cu', 'cu.id', '=', 'cc.curriculum_id')
            ->join('programs as p', 'p.id', '=', 'cu.program_id')->join('academic_units as au', 'au.id', '=', 'p.academic_unit_id')
            ->where('cc.course_id', $id)->distinct()->pluck('au.unit_id')->all();
        return new AcademyTarget(array_merge($viaClasses, $viaCurricula), null, null, ['course' => $course]);
    }

    public function forTranscriptRecord(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $transcript = $this->find('transcripts', $id, $lock);
        $target = $this->forTranscript((int) $transcript->person_id, (int) $transcript->curriculum_id, $this->ancestor($lock));
        $target->rows['transcript'] = $transcript;
        return $target;
    }

    // A transcript aggregates ONE Person's enrollments under a curriculum, and each of those records
    // belongs to the unit of ITS OWN class -- not to the curriculum's unit (the schema does not tie a
    // class's academic unit to its cohort's). Authority is therefore required over the curriculum's unit
    // AND over the unit of every contributing enrollment; the aggregate is one document, so a missing
    // unit denies the whole operation (no partial transcript). The contributing units are peeked WITHOUT
    // a lock on purpose: share-locking the Person's enrollments before the Person row would invert the
    // lock order of EnrollmentService::enroll. TranscriptService re-reads the lines under its own locks
    // and refuses any line whose unit is not in this target (STORAGE_CONFLICT).
    // A null $person (unknown or malformed reference) contributes no units; the caller reports it after authorization.
    public function forTranscript(?int $person, int $curriculumId, ?string $lock = 'share'): AcademyTarget
    {
        $target = $this->forCurriculum($curriculumId, $lock);
        $units = $person === null ? [] : $this->db->table('enrollments as e')->join('classes as c', 'c.id', '=', 'e.class_id')->join('cohorts as h', 'h.id', '=', 'c.cohort_id')
            ->join('academic_units as au', 'au.id', '=', 'c.academic_unit_id')
            ->where('e.person_id', $person)->where('h.curriculum_id', $curriculumId)->distinct()->pluck('au.unit_id')->all();
        $target->unitIds = array_values(array_unique(array_map('intval', array_merge($target->unitIds, $units))));
        return $target;
    }

    // A course version is shared catalog content: the organizational units it belongs to are the
    // units of every class taught from it and of every program whose curricula list its course.
    // Authority is required over ALL of them; a version attached to none is SCOPE_UNRESOLVED.
    public function forCourseVersion(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $version = $this->find('course_versions', $id, $lock);
        $ancestor = $this->ancestor($lock);
        $viaClasses = $this->shared($this->db->table('classes as c')->join('academic_units as au', 'au.id', '=', 'c.academic_unit_id')
            ->where('c.course_version_id', $id)->distinct(), $ancestor)->pluck('au.unit_id')->all();
        $viaCurricula = $this->shared($this->db->table('curriculum_courses as cc')
            ->join('curricula as cu', 'cu.id', '=', 'cc.curriculum_id')
            ->join('programs as p', 'p.id', '=', 'cu.program_id')
            ->join('academic_units as au', 'au.id', '=', 'p.academic_unit_id')
            ->where('cc.course_id', $version->course_id)->distinct(), $ancestor)->pluck('au.unit_id')->all();
        return new AcademyTarget(array_merge($viaClasses, $viaCurricula), null, null, ['course_version' => $version]);
    }

    public function forAssessment(int $id, ?string $lock = 'share'): AcademyTarget
    {
        $peek = $this->find('assessments', $id, null);
        $target = $this->forCourseVersion((int) $peek->course_version_id, $this->ancestor($lock));
        $target->rows['assessment'] = $lock === null ? $peek : $this->root('assessments', $id, 'course_version_id', (int) $target->rows['course_version']->id, $lock);
        return $target;
    }
}
