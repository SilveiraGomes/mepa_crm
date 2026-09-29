<?php

declare(strict_types=1);

namespace App\Domain\People;

use Illuminate\Database\Connection;

// Controlled catalog data approved by ADR-0017 (D02, Permissions V1). The only source of these codes.
// Installed by migration 2026_09_23_000004 and by the test harness after a TRUNCATE reset.
// install() is idempotent, inserts missing rows only and never deletes (no hard delete, D03).
final class PeopleCatalog
{
    public const DATA_TYPE = 'PEOPLE';

    public const PEOPLE_VIEW = 'PEOPLE_VIEW';
    public const PEOPLE_CREATE = 'PEOPLE_CREATE';
    public const PEOPLE_EDIT = 'PEOPLE_EDIT';
    public const PEOPLE_SENSITIVE_VIEW = 'PEOPLE_SENSITIVE_VIEW';
    public const PEOPLE_CONTACT_MANAGE = 'PEOPLE_CONTACT_MANAGE';
    public const PEOPLE_ADDRESS_MANAGE = 'PEOPLE_ADDRESS_MANAGE';
    public const HOUSEHOLD_VIEW = 'HOUSEHOLD_VIEW';
    public const HOUSEHOLD_MANAGE = 'HOUSEHOLD_MANAGE';
    public const RELATIONSHIP_MANAGE = 'RELATIONSHIP_MANAGE';
    public const PEOPLE_EXPORT = 'PEOPLE_EXPORT';
    public const PEOPLE_EXPORT_CLASS_C = 'PEOPLE_EXPORT_CLASS_C';

    /** permission code => permissions.maximum_classification */
    public const PERMISSIONS = [
        self::PEOPLE_VIEW => 'CLASS_B',
        self::PEOPLE_CREATE => 'CLASS_B',
        self::PEOPLE_EDIT => 'CLASS_B',
        self::PEOPLE_SENSITIVE_VIEW => 'CLASS_B',
        self::PEOPLE_CONTACT_MANAGE => 'CLASS_B',
        self::PEOPLE_ADDRESS_MANAGE => 'CLASS_B',
        self::HOUSEHOLD_VIEW => 'CLASS_B',
        self::HOUSEHOLD_MANAGE => 'CLASS_B',
        self::RELATIONSHIP_MANAGE => 'CLASS_B',
        self::PEOPLE_EXPORT => 'CLASS_B',
        self::PEOPLE_EXPORT_CLASS_C => 'CLASS_C',
    ];

    public const PERSON_ACTIVE = 'ACTIVE';
    public const PERSON_INACTIVE = 'INACTIVE';
    public const PERSON_DECEASED = 'DECEASED';
    // MERGED is OUT_OF_SCOPE for P0.5 and deliberately absent.
    public const PERSON_STATUSES = [self::PERSON_ACTIVE => 'Activa', self::PERSON_INACTIVE => 'Inactiva', self::PERSON_DECEASED => 'Falecida'];

    public const HOUSEHOLD_ACTIVE = 'ACTIVE';
    public const HOUSEHOLD_INACTIVE = 'INACTIVE';
    public const HOUSEHOLD_ARCHIVED = 'ARCHIVED';
    public const HOUSEHOLD_STATUSES = [self::HOUSEHOLD_ACTIVE, self::HOUSEHOLD_INACTIVE, self::HOUSEHOLD_ARCHIVED];

    public const HOUSEHOLD_ROLES = ['REFERENCE_PERSON' => 'Pessoa de referência', 'MEMBER' => 'Membro', 'DEPENDENT' => 'Dependente'];

    public const SYMMETRIC = 'SYMMETRIC';
    public const INVERSE_PAIRED = 'INVERSE_PAIRED';
    /** code => [name, semantics, inverse code] */
    public const RELATIONSHIPS = [
        'SPOUSE' => ['Cônjuge', self::SYMMETRIC, 'SPOUSE'],
        'SIBLING' => ['Irmão ou irmã', self::SYMMETRIC, 'SIBLING'],
        'PARENT' => ['Pai ou mãe', self::INVERSE_PAIRED, 'CHILD'],
        'CHILD' => ['Filho ou filha', self::INVERSE_PAIRED, 'PARENT'],
        'GUARDIAN' => ['Responsável (factual)', self::INVERSE_PAIRED, 'DEPENDENT'],
        'DEPENDENT' => ['Dependente (factual)', self::INVERSE_PAIRED, 'GUARDIAN'],
    ];

    // contact_types purpose in the approved model catalog: "Telefone, email e contacto de emergência".
    // Emergency contacts are Class C child data owned by Children and are not a People contact type.
    public const CONTACT_TYPES = ['PHONE' => 'Telefone', 'EMAIL' => 'Email'];

    // Temporal link rows (contacts, person_addresses, household_members, person_relationships) use the
    // same ACTIVE/INACTIVE vocabulary ADR-0017 fixes for person_unit_contexts; the end is ends_at.
    public const LINK_ACTIVE = 'ACTIVE';
    public const LINK_INACTIVE = 'INACTIVE';

    public const CONTEXT_ONBOARDING = 'ONBOARDING';

    public static function install(Connection $db): array
    {
        return $db->transaction(function () use ($db): array {
            $now = $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
            $inserted = [];
            $simple = function (string $table, array $rows) use ($db, $now, &$inserted): void {
                foreach ($rows as $code => $name) {
                    if (!$db->table($table)->where('code', $code)->exists()) {
                        $db->table($table)->insert(['code' => $code, 'name' => $name, 'is_active' => 1, 'created_at' => $now, 'lock_version' => 0]);
                        $inserted[] = $table . ':' . $code;
                    }
                }
            };
            $simple('person_statuses', self::PERSON_STATUSES);
            $simple('household_role_types', self::HOUSEHOLD_ROLES);
            $simple('contact_types', self::CONTACT_TYPES);

            // Relationship types are loaded inactive, paired, validated and only then activated.
            foreach (self::RELATIONSHIPS as $code => [$name, $semantics]) {
                if (!$db->table('relationship_types')->where('code', $code)->exists()) {
                    $db->table('relationship_types')->insert(['code' => $code, 'name' => $name, 'semantics' => $semantics, 'inverse_relationship_type_id' => null, 'is_active' => 0, 'created_at' => $now, 'lock_version' => 0]);
                    $inserted[] = 'relationship_types:' . $code;
                }
            }
            $ids = $db->table('relationship_types')->whereIn('code', array_keys(self::RELATIONSHIPS))->pluck('id', 'code')->all();
            foreach (self::RELATIONSHIPS as $code => [, , $inverse]) {
                $db->table('relationship_types')->where('id', $ids[$code])->whereNull('inverse_relationship_type_id')->update(['inverse_relationship_type_id' => $ids[$inverse]]);
            }
            foreach (array_keys(self::RELATIONSHIPS) as $code) {
                self::assertPairing($db, (int) $ids[$code]);
            }
            $db->table('relationship_types')->whereIn('id', array_values($ids))->update(['is_active' => 1]);

            foreach (self::PERMISSIONS as $code => $classification) {
                if (!$db->table('permissions')->where('code', $code)->exists()) {
                    $db->table('permissions')->insert(['code' => $code, 'action' => $code, 'data_type' => self::DATA_TYPE, 'maximum_classification' => $classification, 'created_at' => $now, 'lock_version' => 0]);
                    $inserted[] = 'permissions:' . $code;
                }
            }
            return $inserted;
        });
    }

    /** SYMMETRIC points to itself; INVERSE_PAIRED points to another type that points back. */
    public static function assertPairing(Connection $db, int $typeId): object
    {
        $type = $db->table('relationship_types')->where('id', $typeId)->first();
        if (!$type || $type->inverse_relationship_type_id === null) {
            throw new PeopleError(PeopleReason::CATALOG_INVALID, ['relationship_type' => $typeId]);
        }
        $inverse = $db->table('relationship_types')->where('id', $type->inverse_relationship_type_id)->first();
        $ok = match ($type->semantics) {
            self::SYMMETRIC => (int) $type->inverse_relationship_type_id === (int) $type->id,
            self::INVERSE_PAIRED => $inverse !== null && (int) $inverse->id !== (int) $type->id
                && $inverse->semantics === self::INVERSE_PAIRED && (int) $inverse->inverse_relationship_type_id === (int) $type->id,
            default => false,
        };
        if (!$ok) {
            throw new PeopleError(PeopleReason::CATALOG_INVALID, ['relationship_type' => $typeId]);
        }
        return $type;
    }
}
