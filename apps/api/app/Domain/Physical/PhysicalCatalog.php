<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use Illuminate\Database\Connection;

// Controlled vocabulary of ADR 0018 (D02, D05, D06, D13). The only source of these codes. install() is
// idempotent, inserts missing rows only and never deletes; it is run by migration 2026_09_29_000002 and by
// the test harness after a TRUNCATE reset. No role is created or changed.
final class PhysicalCatalog
{
    public const DATA_TYPE = 'PHYSICAL';

    public const LOCATION_VIEW = 'PHYSICAL_LOCATION_VIEW';
    public const LOCATION_MANAGE = 'PHYSICAL_LOCATION_MANAGE';
    public const LOCATION_PUBLISH = 'PHYSICAL_LOCATION_PUBLISH';
    public const PROPERTY_VIEW = 'PROPERTY_VIEW';
    public const PROPERTY_MANAGE = 'PROPERTY_MANAGE';
    public const TEMPLE_VIEW = 'TEMPLE_VIEW';
    public const TEMPLE_MANAGE = 'TEMPLE_MANAGE';
    public const LINK_MANAGE = 'UNIT_LOCATION_LINK_MANAGE';

    /** permission code => permissions.maximum_classification (07_data_classification: MANAGE reads Confidential address/owner data) */
    public const PERMISSIONS = [
        self::LOCATION_VIEW => 'RESTRICTED',
        self::LOCATION_MANAGE => 'CONFIDENTIAL',
        self::LOCATION_PUBLISH => 'RESTRICTED',
        self::PROPERTY_VIEW => 'RESTRICTED',
        self::PROPERTY_MANAGE => 'CONFIDENTIAL',
        self::TEMPLE_VIEW => 'RESTRICTED',
        self::TEMPLE_MANAGE => 'RESTRICTED',
        self::LINK_MANAGE => 'RESTRICTED',
    ];

    /** D02: how the unit occupies or uses the location (never the building type). */
    public const OCCUPATION_TYPES = [
        'OWNED' => 'Próprio',
        'RENTED' => 'Arrendado',
        'CEDED' => 'Cedido',
        'BORROWED' => 'Emprestado',
        'TEMPORARY' => 'Provisório',
        'OTHER' => 'Outro',
    ];
    public const OCCUPATION_REQUIRES_REASON = 'OTHER';

    // D05: reused vocabularies, validated in the application (no new CHECK).
    public const DRAFT = 'DRAFT';
    public const ACTIVE = 'ACTIVE';
    public const CLOSED = 'CLOSED';
    public const STATUSES = [self::DRAFT => 'Rascunho', self::ACTIVE => 'Activo', self::CLOSED => 'Encerrado'];
    public const LINK_ACTIVE = 'ACTIVE';
    public const LINK_ENDED = 'ENDED';

    public const PRIVATE = 'PRIVATE';
    public const APPROVED_PUBLIC = 'APPROVED_PUBLIC';

    // D06: documentary only; never grants or removes authority.
    public const OWNERSHIP_STATUSES = [
        'REGISTERED' => 'Registado/titulado',
        'IN_REGULARIZATION' => 'Em regularização',
        'UNREGISTERED' => 'Sem registo formal',
        'UNKNOWN' => 'Não apurado',
    ];
    public const OWNERSHIP_DEFAULT = 'UNKNOWN';

    /** @return list<string> rows inserted by this call */
    public static function install(Connection $db): array
    {
        return $db->transaction(function () use ($db): array {
            $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
            $inserted = [];
            foreach (self::OCCUPATION_TYPES as $code => $name) {
                if (!$db->table('occupation_types')->where('code', $code)->exists()) {
                    $db->table('occupation_types')->insert(['code' => $code, 'name' => $name, 'is_active' => 1, 'created_at' => $now, 'lock_version' => 0]);
                    $inserted[] = 'occupation_types:' . $code;
                }
            }
            foreach (self::PERMISSIONS as $code => $classification) {
                if (!$db->table('permissions')->where('code', $code)->exists()) {
                    $db->table('permissions')->insert([
                        'code' => $code, 'action' => $code, 'data_type' => self::DATA_TYPE,
                        'maximum_classification' => $classification, 'created_at' => $now, 'lock_version' => 0,
                    ]);
                    $inserted[] = 'permissions:' . $code;
                }
            }
            return $inserted;
        });
    }
}
