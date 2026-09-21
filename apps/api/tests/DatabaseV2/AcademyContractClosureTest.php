<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\AcademicAttendanceService;
use App\Domain\Academy\AcademyContextQueryService;
use App\Domain\Academy\AcademyOperation;
use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\ClassQueryService;
use App\Domain\Academy\EligibleFileQueryService;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/** P0.3.5-A4.1 real-MySQL coverage for the API/UI contract closure. */
final class AcademyContractClosureTest extends PooledWaveFiveCase
{
    public function test_context_projects_only_effective_permission_codes_and_authorized_vocabulary(): void
    {
        $w = $this->world();
        $actor = $this->actor($w['unit'], [AcademyOperation::VIEW, AcademyOperation::MANAGE, AcademyOperation::ATTENDANCE]);
        $context = (new AcademyContextQueryService($this->rt()))->context($actor['user'], $actor['session']);

        self::assertSame([AcademyOperation::VIEW, AcademyOperation::MANAGE, AcademyOperation::ATTENDANCE], $context['permissions']);
        self::assertSame([], $context['vocabulary']['transitions']['enrollment']);
        self::assertSame([], $context['vocabulary']['transitions']['session']);
        self::assertSame([], $context['vocabulary']['transitions']['attempt']);
        self::assertSame(['S_ATD_PRESENT', 'S_ATD_ABSENT'], $context['vocabulary']['attendance_statuses']);
        self::assertArrayNotHasKey('roles', $context);
        self::assertArrayNotHasKey('scopes', $context);
    }

    public function test_instructor_candidate_picker_uses_manage_and_exposes_no_internal_person_id(): void
    {
        $w = $this->world();
        $candidate = $this->enrollment($w['class']);
        $publicId = (string) $this->db()->table('people')->where('id', $candidate['person'])->value('public_id');
        $manager = $this->actor($w['unit'], [AcademyOperation::MANAGE]);
        $enroller = $this->actor($w['unit'], [AcademyOperation::ENROLL]);
        $service = new ClassQueryService($this->rt());

        $result = $service->searchInstructorCandidates($manager['user'], $manager['session'], $w['class'], ['search' => $publicId]);
        self::assertSame(1, $result['total']);
        self::assertSame($publicId, $result['items'][0]['public_id']);
        self::assertArrayNotHasKey('id', $result['items'][0]);
        self::assertArrayNotHasKey('person_id', $result['items'][0]);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $service->searchInstructorCandidates($enroller['user'], $enroller['session'], $w['class'], ['search' => $publicId]));
    }

    public function test_eligible_file_picker_is_target_scoped_available_only_and_opaque(): void
    {
        $w = $this->world();
        $foreign = $this->world();
        $student = $this->enrollment($w['class']);
        $certifier = $this->actor($w['unit'], [AcademyOperation::CERTIFY]);
        $viewer = $this->actor($w['unit'], [AcademyOperation::VIEW]);
        $eligible = $this->row('files', ['owner_unit_id' => $w['unit'], 'status' => 'AVAILABLE', 'original_name' => 'certificado.pdf', 'mime_type' => 'application/pdf']);
        $this->row('files', ['owner_unit_id' => $w['unit'], 'status' => 'QUARANTINED', 'original_name' => 'quarentena.pdf']);
        $this->row('files', ['owner_unit_id' => $foreign['unit'], 'status' => 'AVAILABLE', 'original_name' => 'estrangeiro.pdf']);
        $department = $this->row('department_instances', ['unit_id' => $w['unit']]);
        $this->row('files', ['owner_unit_id' => $w['unit'], 'owner_department_id' => $department, 'status' => 'AVAILABLE', 'original_name' => 'departamento.pdf']);
        $service = new EligibleFileQueryService($this->rt());

        $result = $service->forCertificate($certifier['user'], $certifier['session'], $student['id'], []);
        self::assertSame(1, $result['total']);
        self::assertSame((string) $this->db()->table('files')->where('id', $eligible)->value('public_id'), $result['items'][0]['public_id']);
        self::assertSame(['public_id', 'file_name', 'media_type'], array_keys($result['items'][0]));
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $service->forCertificate($viewer['user'], $viewer['session'], $student['id'], []));
    }

    public function test_attendance_read_starts_from_the_roster_before_the_first_recording(): void
    {
        $w = $this->world();
        $student = $this->enrollment($w['class']);
        $classSession = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_PLANNED']);
        $viewer = $this->actor($w['unit'], [AcademyOperation::VIEW]);

        $result = (new AcademicAttendanceService($this->rt()))->listForSession($viewer['user'], $viewer['session'], $classSession);
        self::assertSame(1, $result['total']);
        self::assertSame((string) $this->db()->table('enrollments')->where('id', $student['id'])->value('public_id'), $result['items'][0]['enrollment_public_id']);
        self::assertNull($result['items'][0]['status']);
        self::assertNull($result['items'][0]['recorded_at']);
        self::assertNull($result['items'][0]['lock_version']);
    }
}
