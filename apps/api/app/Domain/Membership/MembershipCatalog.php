<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use Illuminate\Database\Connection;

// Controlled vocabulary of ADR 0020 (D02, D03, D06, D07, D08, D10). The only source of these codes. install() is
// idempotent, inserts missing rows only and never deletes or updates; it is run by migration 2026_09_30_000003 and by
// the test harness after a TRUNCATE reset. No role is created or changed. The national counter row MEPA_NATIONAL is
// inserted with last_value = 0 only when it is absent: install() never touches an existing counter (never a reset).
final class MembershipCatalog
{
    public const DATA_TYPE = 'MEMBERSHIP';
    public const AUDIT_SOURCE = 'P09_MEMBERSHIP';
    /** ADR 0009 ULID: the only external identifier of memberships and transfers. */
    public const PUBLIC_ID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

    public const VIEW = 'MEMBERSHIP_VIEW';
    public const ADMISSION_MANAGE = 'MEMBERSHIP_ADMISSION_MANAGE';
    public const APPROVE = 'MEMBERSHIP_APPROVE';
    public const MANAGE = 'MEMBERSHIP_MANAGE';
    public const TRANSFER = 'MEMBERSHIP_TRANSFER';
    public const LEGACY_MANAGE = 'MEMBERSHIP_LEGACY_MANAGE';

    /** D10: permission code => permissions.maximum_classification (vocabulary of Physical/Files). */
    public const PERMISSIONS = [
        self::VIEW => 'RESTRICTED',
        self::ADMISSION_MANAGE => 'RESTRICTED',
        self::APPROVE => 'RESTRICTED',
        self::MANAGE => 'RESTRICTED',
        self::TRANSFER => 'RESTRICTED',
        self::LEGACY_MANAGE => 'RESTRICTED',
    ];

    // D03: membership_statuses V1. SUBMITTED..WITHDRAWN are candidacies (never a member, never a number).
    public const SUBMITTED = 'SUBMITTED';
    public const VALIDATED = 'VALIDATED';
    public const REJECTED = 'REJECTED';
    public const WITHDRAWN = 'WITHDRAWN';
    public const ACTIVE = 'ACTIVE';
    public const INACTIVE = 'INACTIVE';
    public const ENDED = 'ENDED';
    public const STATUSES = [
        self::SUBMITTED => 'Candidatura submetida',
        self::VALIDATED => 'Candidatura validada',
        self::REJECTED => 'Candidatura não aprovada',
        self::WITHDRAWN => 'Candidatura retirada',
        self::ACTIVE => 'Membro activo',
        self::INACTIVE => 'Membro inactivo',
        self::ENDED => 'Membresia terminada',
    ];
    /** D01.4: a member is a membership with a member_numbers row AND one of these states. */
    public const MEMBER_STATES = [self::ACTIVE, self::INACTIVE];
    public const CANDIDATE_STATES = [self::SUBMITTED, self::VALIDATED, self::REJECTED, self::WITHDRAWN];

    // D04: memberships.origin (service-validated, no CHECK) -> member_numbers.origin.
    public const ORIGIN_ADMISSION = 'ADMISSION';
    public const ORIGIN_LEGACY_IMPORT = 'LEGACY_IMPORT';
    public const NUMBER_ORIGINS = [self::ORIGIN_ADMISSION => 'APPROVED_ADMISSION', self::ORIGIN_LEGACY_IMPORT => 'APPROVED_LEGACY_MAPPING'];

    /** D02 / P0.5 date precision vocabulary. */
    public const PRECISIONS = ['EXACT', 'MONTH', 'YEAR', 'UNKNOWN'];

    // D02: national singleton counter, AA/MM in Africa/Luanda.
    public const COUNTER = 'MEPA_NATIONAL';
    public const NUMBER_TIMEZONE = 'Africa/Luanda';
    public const NUMBER_PATTERN = '/^MEPA\d{2}(0[1-9]|1[0-2])\d{6}$/D';

    // D06: transfers.status V1 (service-validated, no CHECK).
    public const T_REQUESTED = 'REQUESTED';
    public const T_ORIGIN_VALIDATED = 'ORIGIN_VALIDATED';
    public const T_DESTINATION_ACCEPTED = 'DESTINATION_ACCEPTED';
    public const T_COMPLETED = 'COMPLETED';
    public const T_REJECTED = 'REJECTED';
    public const T_CANCELLED = 'CANCELLED';
    public const TRANSFER_STATUSES = [
        self::T_REQUESTED => 'Pedido',
        self::T_ORIGIN_VALIDATED => 'Validada pela origem',
        self::T_DESTINATION_ACCEPTED => 'Aceite pelo destino',
        self::T_COMPLETED => 'Efectivada',
        self::T_REJECTED => 'Rejeitada',
        self::T_CANCELLED => 'Cancelada',
    ];
    public const TRANSFER_OPEN = [self::T_REQUESTED, self::T_ORIGIN_VALIDATED, self::T_DESTINATION_ACCEPTED];
    public const WORKFLOW_TRANSFER = 'MEMBERSHIP_TRANSFER';
    public const WORKFLOW_VERSION = 1;

    // D07: legacy identifiers.
    public const LEGACY_SOURCE = 'MEPA_LEGACY_V1';
    public const LEGACY_SOURCES = [self::LEGACY_SOURCE];
    public const L_ACTIVE = 'ACTIVE';
    public const L_CONFLICT = 'CONFLICT';
    public const L_REVOKED = 'REVOKED';
    public const LEGACY_STATUSES = [self::L_ACTIVE => 'Activo', self::L_CONFLICT => 'Em conflito', self::L_REVOKED => 'Revogado'];

    // D08: milestone types V1 (admission is NOT a milestone).
    public const CONVERSION = 'CONVERSION';
    public const BAPTISM = 'BAPTISM';
    public const MILESTONE_TYPES = [self::CONVERSION => 'Conversão', self::BAPTISM => 'Baptismo'];

    /** @return list<string> rows inserted by this call */
    public static function install(Connection $db): array
    {
        return $db->transaction(function () use ($db): array {
            $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
            $inserted = [];
            foreach (self::STATUSES as $code => $name) {
                if (!$db->table('membership_statuses')->where('code', $code)->exists()) {
                    $db->table('membership_statuses')->insert(['code' => $code, 'name' => $name, 'is_active' => 1, 'created_at' => $now, 'lock_version' => 0]);
                    $inserted[] = 'membership_statuses:' . $code;
                }
            }
            foreach (self::MILESTONE_TYPES as $code => $name) {
                if (!$db->table('milestone_types')->where('code', $code)->exists()) {
                    $db->table('milestone_types')->insert(['code' => $code, 'name' => $name, 'is_active' => 1, 'created_at' => $now, 'lock_version' => 0]);
                    $inserted[] = 'milestone_types:' . $code;
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
            if (!$db->table('workflows')->where('code', self::WORKFLOW_TRANSFER)->where('version', self::WORKFLOW_VERSION)->exists()) {
                $db->table('workflows')->insert(['code' => self::WORKFLOW_TRANSFER, 'version' => self::WORKFLOW_VERSION, 'name' => 'Transferência de membresia', 'status' => 'ACTIVE', 'created_at' => $now, 'lock_version' => 0]);
                $inserted[] = 'workflows:' . self::WORKFLOW_TRANSFER;
            }
            // INSERT IGNORE on the UNIQUE code: a concurrent install can never produce a second row, and an existing
            // counter (whatever its last_value) is never updated.
            if (!$db->table('member_number_sequences')->where('code', self::COUNTER)->exists()) {
                $added = $db->table('member_number_sequences')->insertOrIgnore(['code' => self::COUNTER, 'last_value' => 0, 'created_at' => $now, 'lock_version' => 0]);
                if ($added === 1) {
                    $inserted[] = 'member_number_sequences:' . self::COUNTER;
                }
            }
            return $inserted;
        });
    }

    /** @return array<string, int> status code => membership_statuses.id */
    public static function statusIds(Connection $db): array
    {
        $ids = [];
        foreach ($db->table('membership_statuses')->whereIn('code', array_keys(self::STATUSES))->where('is_active', 1)->get(['id', 'code']) as $row) {
            $ids[(string) $row->code] = (int) $row->id;
        }
        return $ids;
    }
}
