<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Hashing\BcryptHasher;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

final class AcademyE2EFixtureTest extends PooledWaveFiveCase
{
    public function test_seed_real_browser_fixture_only(): void
    {
        $main = $this->world(['code' => 'E2E-CLASS-A', 'status' => 'S_CLS_OPEN']);
        $this->db()->table('academic_units')->where('id', $main['academicUnit'])->update(['code' => 'E2E-A', 'name' => 'Academia E2E A', 'status' => 'SYNTHETIC_READY']);
        $this->db()->table('courses')->where('id', $main['course'])->update(['code' => 'E2E-COURSE', 'name' => 'Curso E2E', 'status' => 'SYNTHETIC_READY']);
        $this->db()->table('course_versions')->where('id', $main['version'])->update(['completion_policy_metadata' => json_encode(['criteria' => [['type' => 'ADMINISTRATIVE_APPROVAL']]], JSON_THROW_ON_ERROR), 'status' => 'SYNTHETIC_READY']);

        $all = ['ACADEMY_VIEW', 'ACADEMY_MANAGE', 'ACADEMY_ENROLL', 'ACADEMY_TEACH', 'ACADEMY_ATTENDANCE', 'ACADEMY_ASSESS', 'ACADEMY_GRADES_VIEW', 'ACADEMY_CERTIFY'];
        $manager = $this->credentialed($this->actor($main['unit'], $all), 'operador.e2e');
        $teacher = $this->credentialed($this->actor($main['unit'], ['ACADEMY_VIEW', 'ACADEMY_TEACH', 'ACADEMY_ATTENDANCE', 'ACADEMY_ASSESS', 'ACADEMY_GRADES_VIEW']), 'instrutor.e2e');
        $certifier = $this->credentialed($this->actor($main['unit'], ['ACADEMY_VIEW', 'ACADEMY_CERTIFY']), 'certificador.e2e');
        $this->assign($teacher, $main['class']);

        $external = $this->row('people', ['full_name' => 'Aluno Externo E2E']);
        $candidate = $this->row('people', ['full_name' => 'Candidato Externo E2E']);
        // Contextual search only exposes people already known inside the authorised
        // academic unit. Keep the candidate out of the target class while making
        // that relationship explicit in an adjacent class in the same unit.
        $candidateSourceClass = $this->row('classes', ['academic_unit_id' => $main['academicUnit'], 'course_version_id' => $main['version'], 'cohort_id' => null, 'code' => 'E2E-CANDIDATE-SOURCE', 'status' => 'S_CLS_OPEN']);
        $this->enrollment($candidateSourceClass, $candidate, 'S_ENR_ACTIVE');
        $minor = $this->row('people', ['full_name' => 'Menor Protegido E2E', 'birth_date' => '2014-04-12', 'birth_precision' => 'EXACT']);
        $externalEnrollment = $this->enrollment($main['class'], $external, 'S_ENR_ACTIVE');
        $minorEnrollment = $this->enrollment($main['class'], $minor, 'S_ENR_ACTIVE');
        $completed = $this->row('people', ['full_name' => 'Aluno Certificado E2E']);
        $completedEnrollment = $this->enrollment($main['class'], $completed, 'S_ENR_COMPLETED');
        $classSession = $this->row('class_sessions', ['class_id' => $main['class'], 'status' => 'S_SES_PLANNED', 'starts_at' => $this->later('+1 hour'), 'ends_at' => $this->later('+2 hours')]);
        $assessment = $this->assessment($main['version'], ['name' => 'Avaliação E2E']);
        $attempt = $this->attempt($assessment, $externalEnrollment['id']);
        $file = $this->row('files', ['owner_unit_id' => $main['unit'], 'created_by' => $certifier['user'], 'status' => 'AVAILABLE', 'original_name' => 'certificado-e2e.pdf', 'mime_type' => 'application/pdf']);

        $other = $this->world(['code' => 'E2E-CLASS-B', 'status' => 'S_CLS_OPEN']);
        $wrongScope = $this->credentialed($this->actor($other['unit'], ['ACADEMY_VIEW']), 'escopo.b.e2e');
        // Same curriculum/course, but a class owned by Unit B: transcript access must be all-or-nothing.
        $mixedClass = $this->row('classes', ['academic_unit_id' => $other['academicUnit'], 'course_version_id' => $main['version'], 'cohort_id' => $main['cohort'], 'code' => 'E2E-MIXED-B', 'status' => 'S_CLS_OPEN']);
        $this->enrollment($mixedClass, $external, 'S_ENR_COMPLETED');

        $missingPolicy = $this->world(['code' => 'E2E-NO-POLICY', 'status' => 'S_CLS_OPEN']);
        $missingActor = $this->credentialed($this->actor($missingPolicy['unit'], $all), 'politica.e2e');
        $this->assign($missingActor, $missingPolicy['class']);
        $missingPerson = $this->row('people', ['full_name' => 'Aluno Sem Política E2E']);
        $missingEnrollment = $this->enrollment($missingPolicy['class'], $missingPerson, 'S_ENR_ACTIVE');
        $expiredToken = 'e2e-expired-' . bin2hex(random_bytes(16));
        $this->db()->table('auth_sessions')->insert(['user_id' => $manager['user'], 'token_hash' => hash('sha256', $expiredToken, true), 'expires_at' => $this->later('-1 minute'), 'revoked_at' => null, 'ip_hash' => null, 'device_id' => null, 'created_at' => $this->later('-2 hours'), 'lock_version' => 0]);

        $manifest = [
            'password' => 'E2E-Password-42!',
            'expired_token' => $expiredToken,
            'accounts' => ['manager' => $manager, 'instructor' => $teacher, 'certifier' => $certifier, 'wrong_scope' => $wrongScope, 'policy' => $missingActor],
            'main' => [
                'class' => $this->publicId('classes', $main['class']), 'class_id' => $main['class'], 'session_id' => $classSession,
                'assessment' => $this->publicId('assessments', $assessment), 'attempt_id' => $attempt,
                'external_person' => $this->publicId('people', $external), 'external_enrollment' => $this->publicId('enrollments', $externalEnrollment['id']),
                'candidate_person' => $this->publicId('people', $candidate),
                'minor_person' => $this->publicId('people', $minor), 'minor_enrollment' => $this->publicId('enrollments', $minorEnrollment['id']),
                'completed_enrollment' => $this->publicId('enrollments', $completedEnrollment['id']), 'file' => $this->publicId('files', $file),
                'curriculum_id' => $main['curriculum'],
            ],
            'wrong_scope_target_class' => $this->publicId('classes', $main['class']),
            'policy_missing' => ['class' => $this->publicId('classes', $missingPolicy['class']), 'enrollment' => $this->publicId('enrollments', $missingEnrollment['id'])],
        ];
        $dir = dirname(__DIR__, 4) . '/docs/reviews/evidence/P0.3.5-A4.2';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($dir . '/fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertFileExists($dir . '/fixtures.json');
    }

    private function credentialed(array $actor, string $login): array
    {
        $hasher = new BcryptHasher(['rounds' => 4]);
        $browserToken = 'e2e-session-' . bin2hex(random_bytes(24));
        $expiresAt = $this->later('+8 hours');
        $this->db()->table('users')->where('id', $actor['user'])->update(['login' => $login, 'password_hash' => $hasher->make('E2E-Password-42!'), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $this->db()->table('user_role_scopes')->where('id', $actor['link'])->update(['status' => 'SYNTHETIC_READY', 'starts_at' => $this->later('-1 hour'), 'ends_at' => null]);
        $this->db()->table('auth_sessions')->where('id', $actor['session'])->update(['token_hash' => hash('sha256', $browserToken, true), 'expires_at' => $expiresAt, 'revoked_at' => null]);
        return $actor + [
            'login' => $login,
            'user_id' => $actor['user'],
            'user_public_id' => $this->publicId('users', $actor['user']),
            'browser_token' => $browserToken,
            'expires_at' => $expiresAt,
        ];
    }

    private function publicId(string $table, int $id): string
    {
        return (string) $this->db()->table($table)->where('id', $id)->value('public_id');
    }
}
