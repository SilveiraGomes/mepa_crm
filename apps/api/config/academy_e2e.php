<?php

// Explicit test-only vocabulary. It is loaded only when APP_ENV=e2e AND
// ACADEMY_E2E_TEST_POLICY=true. It is never a production default (D-09/D-11 remain open).
$ready = ['SYNTHETIC_READY'];

return [
    'enabled' => env('ACADEMY_E2E_TEST_POLICY', false),
    'access_policy' => [
        'version' => 'SYNTHETIC_E2E_V1',
        'states' => array_fill_keys(['users', 'auth_grants', 'devices', 'events', 'sessions', 'registrations', 'lists', 'templates', 'composition', 'ministerial', 'departments', 'memberships'], $ready)
            + ['credentials' => ['ISSUED']],
        'credential_type_ids' => [PHP_INT_MAX],
    ],
    'child_policy' => [
        'version' => 'SYNTHETIC_E2E_V1',
        'vocabulary' => array_fill_keys(['child_profiles', 'guardian_authorizations', 'person_consents', 'child_emergency_contacts', 'child_custody_visits', 'outreach_campaigns', 'outreach_contacts', 'discipleship_tracks', 'discipleship_enrollments', 'discipleship_progress', 'age_band_rules', 'department_transition_recommendations'], $ready)
            + ['custody_closed' => ['SYNTHETIC_COLLECTED'], 'authorization_revoked' => ['SYNTHETIC_REVOKED'], 'consent_revoked' => ['SYNTHETIC_REVOKED'], 'followup_outcomes' => ['SYNTHETIC_RESULT'], 'decision_types' => ['SYNTHETIC_DECISION'], 'date_precisions' => ['UNKNOWN', 'DAY'], 'consent_purposes' => ['SYNTHETIC_PARTICIPATION'], 'authorization_kinds' => ['SYNTHETIC_DELIVER', 'SYNTHETIC_PICKUP', 'SYNTHETIC_GUARDIAN']],
        'identifiers' => ['SYNTHETIC_IN_PERSON'],
        'delivery_kind' => 'SYNTHETIC_DELIVER', 'pickup_kind' => 'SYNTHETIC_PICKUP',
        'guardian_kind' => 'SYNTHETIC_GUARDIAN', 'participation_purpose' => 'SYNTHETIC_PARTICIPATION',
    ],
    'academy' => [
        'policy_version' => 'SYNTHETIC_E2E_V1',
        'states' => [
            'enrollments' => ['initial' => 'S_ENR_PENDING', 'sets' => ['operational' => ['S_ENR_ACTIVE'], 'completed' => ['S_ENR_COMPLETED'], 'approval_targets' => ['S_ENR_ACTIVE']]],
            'classes' => ['initial' => 'S_CLS_DRAFT', 'sets' => ['enrollable' => ['S_CLS_OPEN'], 'teachable' => ['S_CLS_OPEN']]],
            'class_instructors' => ['initial' => 'S_CI_ACTIVE', 'sets' => ['active' => ['S_CI_ACTIVE'], 'ended' => ['S_CI_ENDED']]],
            'instructors' => ['initial' => 'S_INS_ACTIVE', 'sets' => ['active' => ['S_INS_ACTIVE']]],
            'class_sessions' => ['initial' => 'S_SES_PLANNED', 'sets' => ['attendable' => ['S_SES_PLANNED', 'S_SES_HELD']]],
            'academic_attendance' => ['sets' => ['recordable' => ['S_ATD_PRESENT', 'S_ATD_ABSENT'], 'attended' => ['S_ATD_PRESENT']]],
            'assessments' => ['initial' => 'S_ASM_OPEN', 'sets' => ['open' => ['S_ASM_OPEN']]],
            'assessment_attempts' => ['initial' => 'S_TRY_STARTED', 'sets' => ['gradable' => ['S_TRY_STARTED', 'S_TRY_SUBMITTED'], 'submitted' => ['S_TRY_SUBMITTED']]],
            'grades' => ['initial' => 'S_GRD_DRAFT', 'sets' => ['final' => ['S_GRD_FINAL']]],
            'progress' => ['initial' => 'S_PRG_OPEN', 'sets' => ['completed' => ['S_PRG_DONE']]],
            'resource_progress' => ['initial' => 'S_RPG_OPEN', 'sets' => ['verified' => ['S_RPG_DONE']]],
            'certificates' => ['initial' => 'S_CRT_ISSUED', 'sets' => ['revoked' => ['S_CRT_REVOKED']]],
            'transcripts' => ['initial' => 'S_TRN_ISSUED'],
            'curricula' => ['initial' => 'S_CUR_DRAFT', 'sets' => ['published' => ['S_CUR_PUBLISHED']]],
        ],
        'transitions' => ['approved' => [
            'enrollments' => [['S_ENR_PENDING', 'S_ENR_ACTIVE', 'NONE'], ['S_ENR_ACTIVE', 'S_ENR_COMPLETED', 'COMPLETION'], ['S_ENR_ACTIVE', 'S_ENR_WITHDRAWN', 'NONE']],
            'class_instructors' => [['S_CI_ACTIVE', 'S_CI_ENDED', 'NONE']],
            'class_sessions' => [['S_SES_PLANNED', 'S_SES_HELD', 'NONE']],
            'assessment_attempts' => [['S_TRY_STARTED', 'S_TRY_SUBMITTED', 'NONE']],
            'progress' => [['S_PRG_OPEN', 'S_PRG_DONE', 'NONE']],
            'resource_progress' => [['S_RPG_OPEN', 'S_RPG_DONE', 'NONE']],
            'certificates' => [['S_CRT_ISSUED', 'S_CRT_REVOKED', 'NONE']],
            'curricula' => [['S_CUR_DRAFT', 'S_CUR_PUBLISHED', 'NONE']],
        ], 'pending' => ['grades' => [['S_GRD_DRAFT', 'S_GRD_FINAL']]]],
    ],
];
