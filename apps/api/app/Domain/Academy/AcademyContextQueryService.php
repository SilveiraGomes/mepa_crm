<?php

declare(strict_types=1);

namespace App\Domain\Academy;

final class AcademyContextQueryService
{
    public function __construct(private AcademyRuntime $rt) {}

    public function context(int $actor, int $session): array
    {
        $permissions = $this->rt->access->effectivePermissionCodes($actor, $session);
        $held = array_fill_keys($permissions, true);
        $transitions = [];
        foreach ([
            'enrollment' => ['permission' => AcademyOperation::ENROLL, 'kind' => 'enrollments'],
            'session' => ['permission' => AcademyOperation::TEACH, 'kind' => 'class_sessions'],
            'attempt' => ['permission' => AcademyOperation::ASSESS, 'kind' => 'assessment_attempts'],
        ] as $key => $definition) {
            $transitions[$key] = isset($held[$definition['permission']]) ? $this->rt->policy->approvedTransitions($definition['kind']) : [];
        }
        return [
            'permissions' => $permissions,
            'vocabulary' => [
                'transitions' => $transitions,
                'attendance_statuses' => isset($held[AcademyOperation::ATTENDANCE]) ? $this->rt->policy->configuredSet('academic_attendance', 'recordable') : [],
            ],
        ];
    }
}
