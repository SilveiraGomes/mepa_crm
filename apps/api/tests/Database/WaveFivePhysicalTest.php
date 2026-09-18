<?php

declare(strict_types=1);

namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFiveCase.php';

use Tests\Database\Support\WaveFiveCase;

/**
 * P0.3.5-A1 physical schema tests for Wave 5 (Academia). No Academia domain
 * services exist yet (A2) -- these are schema/DB-enforcement tests only,
 * matching WaveFourPhysicalTest.php's style without touching that file.
 */
final class WaveFivePhysicalTest extends WaveFiveCase
{
    protected static bool $physical = true;

    private function names(): array
    {
        return json_decode(file_get_contents(self::$root . '/docs/database/physical/wave5_migrations_manifest.json'), true)['tables'];
    }

    private function snapshot(array $excluded = []): array
    {
        $out = [];
        foreach ($this->db()->select('SHOW TABLES') as $row) {
            $name = array_values((array) $row)[0];
            if (in_array($name, $excluded, true)) {
                continue;
            }
            $create = array_values((array) $this->db()->selectOne('SHOW CREATE TABLE `' . $name . '`'))[1];
            $out[$name] = [
                'ddl' => preg_replace('/ AUTO_INCREMENT=\d+/', '', $create),
                'rows' => $name === 'migrations' ? [] : array_map(fn ($r) => (array) $r, $this->db()->select('SELECT * FROM `' . $name . '` ORDER BY 1')),
            ];
        }
        ksort($out);
        return $out;
    }

    /** Builds one valid row per Wave 5 table (plus the shared Wave 4 `courses` table), reusing FK chains explicitly. */
    private function fixture(): array
    {
        $unit = $this->row('organizational_units');
        $academicUnit = $this->row('academic_units', ['unit_id' => $unit]);
        $program = $this->row('programs', ['academic_unit_id' => $academicUnit]);
        $curriculum = $this->row('curricula', ['program_id' => $program]);
        $course = $this->row('courses');
        $course2 = $this->row('courses');
        $coursePrerequisite = $this->row('course_prerequisites', ['course_id' => $course, 'required_course_id' => $course2]);
        $courseVersion = $this->row('course_versions', ['course_id' => $course]);
        $curriculumCourse = $this->row('curriculum_courses', ['curriculum_id' => $curriculum, 'course_id' => $course]);
        $module = $this->row('course_modules', ['course_version_id' => $courseVersion]);
        $lesson = $this->row('lessons', ['module_id' => $module]);
        $file = $this->row('files');
        $resource = $this->row('resources', ['file_id' => $file]);
        $lessonResource = $this->row('lesson_resources', ['lesson_id' => $lesson, 'resource_id' => $resource]);
        $location = $this->row('physical_locations');
        $cohort = $this->row('cohorts', ['academic_unit_id' => $academicUnit, 'curriculum_id' => $curriculum]);
        $class = $this->row('classes', ['academic_unit_id' => $academicUnit, 'cohort_id' => $cohort, 'course_version_id' => $courseVersion, 'location_id' => $location]);
        $person = $this->row('people');
        $instructor = $this->row('instructors', ['person_id' => $person]);
        $classInstructor = $this->row('class_instructors', ['class_id' => $class, 'instructor_id' => $instructor]);
        $classSession = $this->row('class_sessions', ['class_id' => $class, 'lesson_id' => $lesson]);
        $studentPerson = $this->row('people');
        $enrollment = $this->row('enrollments', ['person_id' => $studentPerson, 'class_id' => $class]);
        $attendance = $this->row('academic_attendance', ['enrollment_id' => $enrollment, 'class_session_id' => $classSession]);
        $resourceProgress = $this->row('resource_progress', ['enrollment_id' => $enrollment, 'resource_id' => $resource]);
        $progress = $this->row('progress', ['enrollment_id' => $enrollment, 'lesson_id' => $lesson, 'completion_ratio' => '0.5000']);
        $assessment = $this->row('assessments', ['course_version_id' => $courseVersion, 'lesson_id' => $lesson, 'max_score' => '20.0000', 'pass_score' => '10.0000', 'max_attempts' => 3, 'weight' => '1.0000']);
        $attempt = $this->row('assessment_attempts', ['assessment_id' => $assessment, 'enrollment_id' => $enrollment, 'attempt_number' => 1]);
        $grade = $this->row('grades', ['attempt_id' => $attempt, 'version' => 1, 'score' => '15.0000']);
        $certificate = $this->row('certificates', ['enrollment_id' => $enrollment, 'version' => 1, 'file_id' => $file]);
        $transcript = $this->row('transcripts', ['person_id' => $studentPerson, 'curriculum_id' => $curriculum, 'version' => 1, 'file_id' => $file]);
        $transcriptLine = $this->row('transcript_lines', ['transcript_id' => $transcript, 'enrollment_id' => $enrollment, 'certificate_id' => $certificate]);

        return compact(
            'unit', 'academicUnit', 'program', 'curriculum', 'course', 'course2', 'coursePrerequisite',
            'courseVersion', 'curriculumCourse', 'module', 'lesson', 'file', 'resource', 'lessonResource',
            'location', 'cohort', 'class', 'person', 'instructor', 'classInstructor', 'classSession',
            'studentPerson', 'enrollment', 'attendance', 'resourceProgress', 'progress', 'assessment',
            'attempt', 'grade', 'certificate', 'transcript', 'transcriptLine'
        );
    }

    public function test_fresh_upgrade_empty_rollback_remigrate_and_populated_rollback_guard(): void
    {
        $names = $this->names();
        $paths = self::$waveFivePaths;

        // FRESH: Wave 1-4 baseline (already migrated in setUpBeforeClass) is untouched by re-running Wave 5.
        $before = $this->snapshot($names);
        self::assertCount(25, self::$migrator->run($paths));
        self::assertSame($before, $this->snapshot($names));

        $fresh = $this->snapshot();

        // EMPTY ROLLBACK: Wave 5 rolls back cleanly, Wave 1-4 baseline unchanged.
        self::assertCount(25, self::$migrator->rollback($paths));
        self::assertSame($before, $this->snapshot($names));

        // REMIGRATE: re-applying Wave 5 reproduces the identical fresh schema.
        self::assertCount(25, self::$migrator->run($paths));
        self::assertSame($fresh, $this->snapshot());
        self::assertCount(25, self::$migrator->rollback($paths));

        // UPGRADE: populate a Wave 1-4 dataset (people, organizational_units, physical_locations,
        // courses, events/event_sessions among the dependencies Wave 5 FKs reach into), checksum it,
        // then apply Wave 5 on top and confirm the Wave 1-4 data/DDL is byte-for-byte unchanged.
        $unit = $this->row('organizational_units');
        $person = $this->row('people');
        $location = $this->row('physical_locations');
        $course = $this->row('courses');
        $event = $this->row('events', ['owner_unit_id' => $unit]);
        $eventSession = $this->row('event_sessions', ['event_id' => $event]);
        $populated = $this->snapshot($names);
        self::assertCount(25, self::$migrator->run($paths));
        self::assertSame($populated, $this->snapshot($names), 'Wave 1-4 data and DDL must be byte-for-byte preserved after Wave 5 upgrade');

        // Populate every single Wave 5 table with durable synthetic data.
        $f = $this->fixture();
        foreach ($names as $name) {
            self::assertTrue($this->db()->table($name)->exists(), "Wave 5 table {$name} must contain durable data for the rollback-guard test");
        }
        $durable = $this->snapshot();
        $migrationRows = $this->db()->table('migrations')->orderBy('id')->get()->all();

        // ROLLBACK BLOCKED: durable Wave 5 data must refuse rollback, without any partial loss.
        try {
            self::$migrator->rollback($paths);
            self::fail('Durable Wave 5 rollback must refuse');
        } catch (\RuntimeException $e) {
            self::assertSame('WAVE5_DURABLE_DATA_ROLLBACK_BLOCKED', $e->getMessage());
        }
        self::assertSame($durable, $this->snapshot());
        self::assertEquals($migrationRows, $this->db()->table('migrations')->orderBy('id')->get()->all());

        // Clean up synthetic-only data (test harness convention, not a deployment bypass), then
        // confirm empty rollback + remigrate succeed again from the cleaned state.
        $this->db()->beginTransaction();
        foreach (array_reverse($names) as $name) {
            $this->db()->table($name)->delete();
        }
        $this->db()->commit();
        $baseline = $this->snapshot($names);
        self::assertCount(25, self::$migrator->rollback($paths));
        self::assertSame($baseline, $this->snapshot($names));
        self::assertCount(25, self::$migrator->run($paths));
        self::assertSame($baseline, $this->snapshot($names));
        self::assertSame(1, (int) $this->db()->selectOne('SELECT @@foreign_key_checks AS value')->value);

        file_put_contents(self::$root . '/docs/database/physical/wave5_lifecycle_evidence.json', json_encode([
            'fresh' => 'PASS', 'upgrade' => 'PASS', 'empty_rollback' => 'PASS', 'remigrate' => 'PASS',
            'durable_rollback' => 'BLOCKED_WITHOUT_LOSS_PASS',
            'wave4_data_preserved_across_wave5_upgrade' => true,
            'all_wave5_tables_populated' => count($names),
            'migration_rows_preserved' => true,
            'baseline_tables' => count($baseline),
            'baseline_sha256' => hash('sha256', serialize($baseline)),
        ], JSON_PRETTY_PRINT) . PHP_EOL);
    }

    public function test_foreign_keys_unique_and_check_constraints_are_enforced(): void
    {
        $this->db()->beginTransaction();
        try {
            $f = $this->fixture();
            $cases = [
                // UNIQUE violations (1062)
                [1062, fn () => $this->row('enrollments', ['person_id' => $f['studentPerson'], 'class_id' => $f['class']])],
                [1062, fn () => $this->row('academic_attendance', ['enrollment_id' => $f['enrollment'], 'class_session_id' => $f['classSession']])],
                [1062, fn () => $this->row('assessment_attempts', ['assessment_id' => $f['assessment'], 'enrollment_id' => $f['enrollment'], 'attempt_number' => 1])],
                [1062, fn () => $this->row('grades', ['attempt_id' => $f['attempt'], 'version' => 1, 'score' => '1.0000'])],
                [1062, fn () => $this->row('certificates', ['enrollment_id' => $f['enrollment'], 'version' => 1, 'file_id' => $f['file']])],
                [1062, fn () => $this->row('instructors', ['person_id' => $f['instructor'] === null ? $f['person'] : $f['person']])],
                // Orphan FK inserts (1452)
                [1452, fn () => $this->row('academic_units', ['unit_id' => PHP_INT_MAX])],
                [1452, fn () => $this->row('classes', ['academic_unit_id' => $f['academicUnit'], 'cohort_id' => $f['cohort'], 'course_version_id' => PHP_INT_MAX])],
                [1452, fn () => $this->row('enrollments', ['person_id' => PHP_INT_MAX, 'class_id' => $f['class']])],
                // CHECK violations (3819)
                [3819, fn () => $this->row('curricula', ['program_id' => $f['program'], 'version' => 0])],
                [3819, fn () => $this->row('course_prerequisites', ['course_id' => $f['course'], 'required_course_id' => $f['course']])],
                [3819, fn () => $this->row('assessments', ['course_version_id' => $f['courseVersion'], 'max_score' => '5.0000', 'pass_score' => '10.0000', 'max_attempts' => 1, 'weight' => '1.0000'])],
                [3819, fn () => $this->row('assessments', ['course_version_id' => $f['courseVersion'], 'max_score' => '20.0000', 'pass_score' => '10.0000', 'max_attempts' => 0, 'weight' => '1.0000'])],
                [3819, fn () => $this->row('class_sessions', ['class_id' => $f['class'], 'starts_at' => '2026-09-18 10:00:00.000000', 'ends_at' => '2026-09-18 09:00:00.000000'])],
                [3819, fn () => $this->row('progress', ['enrollment_id' => $f['enrollment'], 'lesson_id' => $f['lesson'], 'completion_ratio' => '1.5000'])],
                // FK RESTRICT on delete of a referenced parent (1451)
                [1451, fn () => $this->db()->table('classes')->where('id', $f['class'])->delete()],
                [1451, fn () => $this->db()->table('people')->where('id', $f['studentPerson'])->delete()],
                [1451, fn () => $this->db()->table('courses')->where('id', $f['course'])->delete()],
            ];
            foreach ($cases as [$expected, $operation]) {
                try {
                    $operation();
                    self::fail('Database must reject invalid Wave 5 write (expected MySQL error ' . $expected . ')');
                } catch (\Illuminate\Database\QueryException $e) {
                    self::assertSame($expected, (int) ($e->errorInfo[1] ?? 0), (string) $e->getMessage());
                }
            }
            self::assertTrue($this->db()->table('enrollments')->where('id', $f['enrollment'])->exists());
            self::assertTrue($this->db()->table('classes')->where('id', $f['class'])->exists());
        } finally {
            $this->db()->rollBack();
        }
    }

    /** P1-P6 (mission Section 46): Person reuse, no membership dependency, no parallel identity. */
    public function test_person_reuse_non_member_and_no_parallel_identity(): void
    {
        $this->db()->beginTransaction();
        try {
            // P1: instructors/enrollments reference people.id directly.
            $person = $this->row('people');
            $instructor = $this->row('instructors', ['person_id' => $person]);
            self::assertSame($person, (int) $this->db()->table('instructors')->where('id', $instructor)->value('person_id'));

            // P2/P3: no membership or member_number dependency exists in the Wave 5 schema at all.
            foreach (['academic_units', 'programs', 'curricula', 'curriculum_courses', 'course_versions', 'course_prerequisites', 'course_modules', 'lessons', 'resources', 'lesson_resources', 'cohorts', 'classes', 'instructors', 'class_instructors', 'class_sessions', 'enrollments', 'academic_attendance', 'assessments', 'assessment_attempts', 'grades', 'progress', 'resource_progress', 'certificates', 'transcripts', 'transcript_lines'] as $table) {
                $cols = array_map(fn ($c) => $c->Field, $this->db()->select("SHOW COLUMNS FROM `{$table}`"));
                foreach ($cols as $col) {
                    self::assertStringNotContainsStringIgnoringCase('membership', $col, "{$table}.{$col} must not reference membership");
                    self::assertStringNotContainsStringIgnoringCase('member_number', $col, "{$table}.{$col} must not reference member_number");
                    foreach (['student_name', 'teacher_name', 'guardian_name', 'person_name', 'member_name', 'instructor_name', 'first_name', 'last_name'] as $forbidden) {
                        self::assertNotSame($forbidden, $col, "{$table} must not carry a parallel-identity column '{$forbidden}'");
                    }
                }
            }

            // P4: no `students`/`teachers`/`guardians` tables exist as identity registries.
            $tables = array_map(fn ($r) => array_values((array) $r)[0], $this->db()->select('SHOW TABLES'));
            foreach (['students', 'teachers', 'guardians', 'academic_students', 'academic_teachers'] as $forbidden) {
                self::assertNotContains($forbidden, $tables);
            }

            // P5: external instructor (no membership) can exist.
            $externalPerson = $this->row('people');
            $externalInstructor = $this->row('instructors', ['person_id' => $externalPerson]);
            self::assertFalse($this->db()->table('memberships')->where('person_id', $externalPerson)->exists());
            self::assertTrue($this->db()->table('instructors')->where('id', $externalInstructor)->exists());

            // P6: external (non-member) student can enroll -- enrollments has no membership FK.
            $unit = $this->row('organizational_units');
            $academicUnit = $this->row('academic_units', ['unit_id' => $unit]);
            $program = $this->row('programs', ['academic_unit_id' => $academicUnit]);
            $curriculum = $this->row('curricula', ['program_id' => $program]);
            $course = $this->row('courses');
            $courseVersion = $this->row('course_versions', ['course_id' => $course]);
            $cohort = $this->row('cohorts', ['academic_unit_id' => $academicUnit, 'curriculum_id' => $curriculum]);
            $class = $this->row('classes', ['academic_unit_id' => $academicUnit, 'cohort_id' => $cohort, 'course_version_id' => $courseVersion]);
            $externalStudent = $this->row('people');
            self::assertFalse($this->db()->table('memberships')->where('person_id', $externalStudent)->exists());
            $enrollment = $this->row('enrollments', ['person_id' => $externalStudent, 'class_id' => $class]);
            self::assertTrue($this->db()->table('enrollments')->where('id', $enrollment)->exists());
        } finally {
            $this->db()->rollBack();
        }
    }

    /** L1-L6 (mission Section 47): ADR-0015 Decision A, location/pole architecture. */
    public function test_classes_location_columns_match_closed_decision(): void
    {
        $cols = array_map(fn ($c) => $c->Field, $this->db()->select('SHOW COLUMNS FROM `classes`'));
        self::assertContains('academic_unit_id', $cols, 'L1: classes.academic_unit_id must exist');
        self::assertNotContains('unit_id', $cols, 'L2: classes.unit_id was REJECTED in ADR-0015 and must not exist');
        self::assertContains('location_id', $cols, 'L3: classes.location_id was ACCEPTED in ADR-0015');

        $locationCol = $this->db()->selectOne("SHOW COLUMNS FROM `classes` WHERE Field = 'location_id'");
        self::assertSame('YES', $locationCol->Null, 'L4: location_id must be nullable');

        $fk = $this->db()->selectOne("
            SELECT kcu.referenced_table_name AS target, rc.delete_rule AS delete_rule, rc.update_rule AS update_rule
            FROM information_schema.key_column_usage kcu
            JOIN information_schema.referential_constraints rc
              ON rc.constraint_name = kcu.constraint_name AND rc.constraint_schema = kcu.constraint_schema
            WHERE kcu.table_schema = DATABASE() AND kcu.table_name = 'classes' AND kcu.column_name = 'location_id'
        ");
        self::assertSame('physical_locations', $fk->target, 'L5: location_id must reference physical_locations');
        self::assertSame('RESTRICT', $fk->delete_rule);
        self::assertSame('RESTRICT', $fk->update_rule);

        $eventSessionCol = $this->db()->selectOne("SHOW COLUMNS FROM `class_sessions` WHERE Field = 'event_session_id'");
        self::assertSame('YES', $eventSessionCol->Null, 'L6: class_sessions.event_session_id must be optional');
    }

    /** S1-S4 (mission Section 48): ADR-0015 Decision B, no new scope architecture. */
    public function test_scope_structure_unchanged(): void
    {
        $tables = array_map(fn ($r) => array_values((array) $r)[0], $this->db()->select('SHOW TABLES'));
        foreach (['class_scopes', 'academic_scopes', 'class_authorizations'] as $forbidden) {
            self::assertNotContains($forbidden, $tables, "S1: no new class-scope table ({$forbidden}) may exist");
        }

        $scopeKindCheck = $this->db()->selectOne("
            SELECT cc.check_clause AS check_clause FROM information_schema.check_constraints cc
            JOIN information_schema.table_constraints tc
              ON tc.constraint_name = cc.constraint_name AND tc.constraint_schema = cc.constraint_schema
            WHERE tc.table_schema = DATABASE() AND tc.table_name = 'scopes' AND tc.constraint_type = 'CHECK'
            AND cc.check_clause LIKE '%scope_kind%'
        ");
        self::assertStringNotContainsString('CLASS', $scopeKindCheck->check_clause, "S2: scopes.scope_kind must not gain a new academic/class value");
        self::assertStringContainsString('UNIT', $scopeKindCheck->check_clause);

        self::assertContains('class_instructors', $tables, 'S3: class_instructors must exist as the granular provenance table');
        $classInstructorCols = array_map(fn ($c) => $c->Field, $this->db()->select('SHOW COLUMNS FROM `class_instructors`'));
        foreach (['class_id', 'instructor_id', 'status', 'starts_at', 'ends_at'] as $expected) {
            self::assertContains($expected, $classInstructorCols);
        }

        $userRoleScopeCols = array_map(fn ($c) => $c->Field, $this->db()->select('SHOW COLUMNS FROM `user_role_scopes`'));
        self::assertSame(['id', 'user_id', 'role_id', 'scope_id', 'granted_by', 'status', 'starts_at', 'ends_at', 'reason', 'source_document_id', 'created_at', 'lock_version'], $userRoleScopeCols, 'S4: user_role_scopes must be structurally unchanged from Wave 2');
    }

    /** Mission Sections 21-22, 49-50: D-09 policy values and D-11 closed-enum status must not be frozen. */
    public function test_no_frozen_d09_policy_or_d11_closed_status_enum(): void
    {
        $wave5Tables = $this->names();
        $placeholders = implode(',', array_fill(0, count($wave5Tables), '?'));

        $statusChecks = $this->db()->select("
            SELECT tc.table_name AS table_name, cc.check_clause AS check_clause
            FROM information_schema.table_constraints tc
            JOIN information_schema.check_constraints cc
              ON cc.constraint_name = tc.constraint_name AND cc.constraint_schema = tc.constraint_schema
            WHERE tc.table_schema = DATABASE() AND tc.constraint_type = 'CHECK' AND tc.table_name IN ({$placeholders})
        ", $wave5Tables);

        foreach ($statusChecks as $row) {
            self::assertStringNotContainsStringIgnoringCase("status", $row->check_clause, "D-11: {$row->table_name} must not have a closed CHECK enum on a status/kind column while D-11 is BLOCKED (found: {$row->check_clause})");
        }

        // D-09: no CHECK may encode a specific institutional cutoff/scale/attempt-count literal.
        // The only numeric literals permitted are structural floors (0, 1) shared by every ordinal/
        // version/boolean column across the whole schema, not an academic policy value.
        foreach ($this->db()->select("
            SELECT tc.table_name AS table_name, cc.check_clause AS check_clause
            FROM information_schema.table_constraints tc
            JOIN information_schema.check_constraints cc
              ON cc.constraint_name = tc.constraint_name AND cc.constraint_schema = tc.constraint_schema
            WHERE tc.table_schema = DATABASE() AND tc.constraint_type = 'CHECK'
              AND tc.table_name IN ('assessments','assessment_attempts','grades','curricula','course_versions','certificates','transcripts')
        ", []) as $row) {
            preg_match_all('/\b\d+(\.\d+)?\b/', $row->check_clause, $numbers);
            foreach ($numbers[0] as $n) {
                self::assertContains((float) $n, [0.0, 1.0], "D-09: {$row->table_name} CHECK must not encode an institutional policy literal (found '{$n}' in: {$row->check_clause})");
            }
        }

        // No permissions/seed rows for the 9 proposed ACADEMY_* codes exist (Section 33: no institutional seed this phase).
        self::assertSame(0, (int) $this->db()->table('permissions')->where('code', 'like', 'ACADEMY_%')->count(), 'No ACADEMY_* permission seed may exist in A1');
    }

    public function test_courses_wave4_table_is_forward_only_no_change(): void
    {
        $cols = array_map(fn ($c) => $c->Field, $this->db()->select('SHOW COLUMNS FROM `courses`'));
        self::assertSame(['id', 'public_id', 'code', 'name', 'eligibility_policy_version', 'status', 'created_at', 'lock_version'], $cols, 'courses must be unchanged by Wave 5 (NO_CHANGE classification)');
    }
}
