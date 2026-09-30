<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Database\Connection;

// Consumer relations of ADR 0019 D01/D05/D10. Files is transversal: each consumer keeps its FK and its own relation
// rule. The referencing columns are read from INFORMATION_SCHEMA (every FK to files.id / legal_documents.id, so a new
// consumer can never be silently forgotten).
//
// Consumer authority (D05.4), cumulative with permission + scope + clearance + AVAILABLE:
//   document_versions.file_id -> Documents adapter: DOCUMENTS_VIEW over the document owner unit, clearance >= the
//                                HIGHEST classification among the document's versions, ARCHIVED only with
//                                DOCUMENTS_MANAGE;
//   ANY other consumer        -> no adapter in the foundation (People, Children, Academy, Events, credentials,
//                                imports): DENY, concealed 404 (fail closed). Consumers serve their own flows.
// A referenced file is IN USE: no tombstone, no owner change (409 FILE_IN_USE).
final class FilesConsumers
{
    public const DOCUMENT_VERSIONS = 'document_versions.file_id';
    public const DOCUMENT_OWNER = 'document_versions.document_id';

    /** @var array<string, list<array{0: string, 1: string}>> */
    private static array $columns = [];

    public function __construct(private Connection $db)
    {
    }

    /** @return list<array{0: string, 1: string}> [table, column] of every FK to $table.id */
    public function referencing(string $table): array
    {
        $key = $this->db->getDatabaseName() . '|' . $table;
        return self::$columns[$key] ??= array_map(
            fn (object $r): array => [(string) $r->t, (string) $r->c],
            $this->db->select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = 'id' AND TABLE_NAME <> ? ORDER BY TABLE_NAME, COLUMN_NAME", [$table, $table])
        );
    }

    /** @return list<string> "table.column" of every consumer row referencing the file */
    public function fileReferences(int $fileId, bool $lock = false): array
    {
        return $this->references('files', $fileId, $lock, []);
    }

    /** @return list<string> consumers of a legal document OTHER than its own versions */
    public function documentReferences(int $documentId, bool $lock = false): array
    {
        return $this->references('legal_documents', $documentId, $lock, [self::DOCUMENT_OWNER]);
    }

    /** Highest consumer floor of a referenced file (null = no floor); a minor's file is HIGHLY_SENSITIVE. */
    public function floor(int $fileId, array $refs): ?string
    {
        $rank = 0;
        foreach ($refs as $ref) {
            if (isset(FileClassification::FLOORS[$ref])) {
                $rank = max($rank, FileClassification::ORDER[FileClassification::FLOORS[$ref]]);
            }
            if (in_array($ref, ['person_files.file_id', 'person_documents.file_id'], true)) {
                [$table] = explode('.', $ref);
                $minor = $this->db->table($table . ' as x')->join('child_profiles as cp', 'cp.person_id', '=', 'x.person_id')->where('x.file_id', $fileId)->exists();
                if ($minor) {
                    $rank = FileClassification::ORDER[FileClassification::HIGHLY_SENSITIVE];
                }
            }
        }
        return $rank === 0 ? null : FileClassification::fromRank($rank);
    }

    /**
     * Consumer authority for reading/downloading a file. Returns the owning document when the file is a document
     * version (Documents adapter), null for an unreferenced file; any consumer without an adapter => concealed.
     */
    public function authorize(FilesAuthority $authority, TerritorialActor $actor, object $file, array $refs, bool $lock = false): ?object
    {
        foreach ($refs as $ref) {
            if ($ref !== self::DOCUMENT_VERSIONS) {
                throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'consumer_without_adapter', 'consumer' => $ref]);
            }
        }
        if (!in_array(self::DOCUMENT_VERSIONS, $refs, true)) {
            return null;
        }
        $query = $this->db->table('document_versions as dv')->join('legal_documents as d', 'd.id', '=', 'dv.document_id')->where('dv.file_id', (int) $file->id)->select('d.*');
        $documents = ($lock ? $query->sharedLock() : $query)->get()->all();
        if (count($documents) !== 1) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'document_version_ambiguous']);
        }
        $this->documentAuthority($authority, $actor, $documents[0], $lock);
        return $documents[0];
    }

    /** Documents adapter (D08): DOCUMENTS_VIEW in scope, clearance >= max version classification, ARCHIVED only for MANAGE. */
    public function documentAuthority(FilesAuthority $authority, TerritorialActor $actor, object $document, bool $lock = false): string
    {
        $unit = (int) $document->owner_unit_id;
        $authority->requireUnit($actor, FilesCatalog::DOCUMENTS_VIEW, $unit, $lock);
        if ($document->status === FilesCatalog::DOCUMENT_ARCHIVED) {
            $authority->requireUnit($actor, FilesCatalog::DOCUMENTS_MANAGE, $unit, $lock);
        } elseif ($document->status !== FilesCatalog::DOCUMENT_ACTIVE) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'document_status_unknown']);
        }
        $classification = $this->documentClassification((int) $document->id, $lock);
        if (!FileClassification::allows($authority->clearance($actor, $unit, $lock), $classification)) {
            throw new FilesError(FilesReason::OUT_OF_SCOPE, ['reason' => 'document_clearance']);
        }
        return $classification;
    }

    /** Highest classification among a document's versions; an unknown value anywhere fails closed (concealed). */
    public function documentClassification(int $documentId, bool $lock = false): string
    {
        $query = $this->db->table('document_versions as dv')->join('files as f', 'f.id', '=', 'dv.file_id')->where('dv.document_id', $documentId);
        $classes = ($lock ? $query->sharedLock() : $query)->pluck('f.classification')->all();
        if ($classes === []) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'document_without_version']);
        }
        foreach ($classes as $class) {
            if (!FileClassification::isKnown($class)) {
                throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'unknown_classification']);
            }
        }
        return FileClassification::max(...$classes);
    }

    /**
     * SQL predicate (no bindings: ints and closed codes only) — the file aliased $f has no consumer without an adapter,
     * and when it is a document version the document is visible to the actor (see documentVisible()).
     */
    public function fileListPredicate(string $f, FilesAuthority $authority, TerritorialActor $actor): string
    {
        $parts = [];
        foreach ($this->referencing('files') as [$table, $column]) {
            if ($table . '.' . $column !== self::DOCUMENT_VERSIONS) {
                $parts[] = "NOT EXISTS (SELECT 1 FROM `{$table}` c WHERE c.`{$column}` = {$f}.id)";
            }
        }
        $parts[] = "(NOT EXISTS (SELECT 1 FROM document_versions dvx WHERE dvx.file_id = {$f}.id) OR EXISTS (SELECT 1 FROM document_versions dvx JOIN legal_documents dx ON dx.id = dvx.document_id WHERE dvx.file_id = {$f}.id AND " . $this->documentVisible('dx', $authority, $actor) . '))';
        return '(' . implode(' AND ', $parts) . ')';
    }

    /** SQL predicate: document $d is visible (DOCUMENTS_VIEW scope, per-unit clearance over ALL versions, status rule). */
    public function documentVisible(string $d, FilesAuthority $authority, TerritorialActor $actor, bool $archivedOnly = false): string
    {
        $view = $authority->covered($actor, FilesCatalog::DOCUMENTS_VIEW);
        if ($view === []) {
            return '1 = 0';
        }
        $manage = array_keys($authority->covered($actor, FilesCatalog::DOCUMENTS_MANAGE));
        $manageList = $manage === [] ? 'NULL' : implode(',', array_map('intval', $manage));
        $status = $archivedOnly
            ? "({$d}.status = 'ARCHIVED' AND {$d}.owner_unit_id IN ({$manageList}))"
            : "({$d}.status = 'ACTIVE')";
        $groups = [];
        foreach ($authority->clearanceGroups($actor, $view) as $rank => $units) {
            $allowed = implode(',', array_map(fn (string $c): string => "'" . $c . "'", FileClassification::allowedFor((int) $rank)));
            $groups[] = "({$d}.owner_unit_id IN (" . implode(',', array_map('intval', $units)) . ") AND NOT EXISTS (SELECT 1 FROM document_versions v2 JOIN files f2 ON f2.id = v2.file_id WHERE v2.document_id = {$d}.id AND f2.classification NOT IN ({$allowed})) AND EXISTS (SELECT 1 FROM document_versions v3 WHERE v3.document_id = {$d}.id))";
        }
        return '(' . $status . ' AND (' . implode(' OR ', $groups) . '))';
    }

    /** @param list<string> $ignore */
    private function references(string $target, int $id, bool $lock, array $ignore): array
    {
        $found = [];
        foreach ($this->referencing($target) as [$table, $column]) {
            $ref = $table . '.' . $column;
            if (in_array($ref, $ignore, true)) {
                continue;
            }
            $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1" . ($lock ? ' FOR SHARE' : '');
            if ($this->db->selectOne($sql, [$id]) !== null) {
                $found[] = $ref;
            }
        }
        return $found;
    }
}
