<?php

declare(strict_types=1);

namespace App\Domain\Files;

use Illuminate\Database\Connection;

// Controlled vocabulary of ADR 0019 (D07, D08, D11, D12). The only source of these codes. install() is idempotent,
// inserts missing rows only and never deletes; it is run by migration 2026_09_30_000001 and by the test harness after
// a TRUNCATE reset. No role is created or changed, and no DDL is involved (ADR 0019 "Schema delta").
final class FilesCatalog
{
    public const DATA_TYPE = 'FILES';
    /** ADR 0009 ULID: the only external identifier of files, legal_documents and document_versions (D04). */
    public const PUBLIC_ID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';
    public const AUDIT_SOURCE = 'P08_FILES';

    public const FILES_VIEW = 'FILES_VIEW';
    public const FILES_UPLOAD = 'FILES_UPLOAD';
    public const FILES_DOWNLOAD = 'FILES_DOWNLOAD';
    public const FILES_MANAGE = 'FILES_MANAGE';
    public const FILES_CONFIDENTIAL_ACCESS = 'FILES_CONFIDENTIAL_ACCESS';
    public const FILES_HIGHLY_SENSITIVE_ACCESS = 'FILES_HIGHLY_SENSITIVE_ACCESS';
    public const DOCUMENTS_VIEW = 'DOCUMENTS_VIEW';
    public const DOCUMENTS_MANAGE = 'DOCUMENTS_MANAGE';
    public const DOCUMENTS_VERSION_MANAGE = 'DOCUMENTS_VERSION_MANAGE';

    /** D11: permission code => permissions.maximum_classification */
    public const PERMISSIONS = [
        self::FILES_VIEW => 'RESTRICTED',
        self::FILES_UPLOAD => 'RESTRICTED',
        self::FILES_DOWNLOAD => 'RESTRICTED',
        self::FILES_MANAGE => 'RESTRICTED',
        self::FILES_CONFIDENTIAL_ACCESS => 'CONFIDENTIAL',
        self::FILES_HIGHLY_SENSITIVE_ACCESS => 'HIGHLY_SENSITIVE',
        self::DOCUMENTS_VIEW => 'RESTRICTED',
        self::DOCUMENTS_MANAGE => 'RESTRICTED',
        self::DOCUMENTS_VERSION_MANAGE => 'RESTRICTED',
    ];

    /** D08: legal_document_types V1 (names may change institutionally, codes may not). OTHER requires a descriptive title. */
    public const DOCUMENT_TYPES = [
        'MINUTES' => 'Acta',
        'RESOLUTION' => 'Resolução / deliberação',
        'APPOINTMENT' => 'Nomeação / credencial de cargo',
        'CORRESPONDENCE' => 'Ofício / carta / declaração',
        'CONTRACT' => 'Contrato / arrendamento / cedência',
        'PROPERTY_TITLE' => 'Título / registo de propriedade',
        'CONSENT' => 'Consentimento / autorização',
        'OTHER' => 'Outro',
    ];
    public const DOCUMENT_TYPE_REQUIRES_TITLE = 'OTHER';

    // D07: files.status (existing CHECK) and legal_documents.status V1 (application vocabulary, no new CHECK).
    public const QUARANTINED = 'QUARANTINED';
    public const AVAILABLE = 'AVAILABLE';
    public const TOMBSTONE = 'TOMBSTONE';
    public const PURGED = 'PURGED';
    public const FILE_STATUSES = [self::QUARANTINED => 'Em quarentena', self::AVAILABLE => 'Disponível', self::TOMBSTONE => 'Retirado', self::PURGED => 'Destruído'];
    public const DOCUMENT_ACTIVE = 'ACTIVE';
    public const DOCUMENT_ARCHIVED = 'ARCHIVED';
    public const DOCUMENT_STATUSES = [self::DOCUMENT_ACTIVE => 'Activo', self::DOCUMENT_ARCHIVED => 'Arquivado'];

    /** @return list<string> rows inserted by this call */
    public static function install(Connection $db): array
    {
        return $db->transaction(function () use ($db): array {
            $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
            $inserted = [];
            foreach (self::PERMISSIONS as $code => $classification) {
                if (!$db->table('permissions')->where('code', $code)->exists()) {
                    $db->table('permissions')->insert([
                        'code' => $code, 'action' => $code, 'data_type' => self::DATA_TYPE,
                        'maximum_classification' => $classification, 'created_at' => $now, 'lock_version' => 0,
                    ]);
                    $inserted[] = 'permissions:' . $code;
                }
            }
            foreach (self::DOCUMENT_TYPES as $code => $name) {
                if (!$db->table('legal_document_types')->where('code', $code)->exists()) {
                    $db->table('legal_document_types')->insert(['code' => $code, 'name' => $name, 'is_active' => 1, 'created_at' => $now, 'lock_version' => 0]);
                    $inserted[] = 'legal_document_types:' . $code;
                }
            }
            return $inserted;
        });
    }
}
