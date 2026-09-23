<?php

declare(strict_types=1);

namespace App\Domain\People;

// People export (ADR-0017 D03, "Export"). Never granted by a role name and never national just because
// the actor holds PEOPLE_EXPORT: the rows are exactly the People with a GENERAL context inside the
// intersection of the scopes of every permission the export class needs.
//   STANDARD  PEOPLE_EXPORT                                 public_id, name, status, birth precision, age band
//   SENSITIVE PEOPLE_EXPORT + PEOPLE_SENSITIVE_VIEW         + birth components and primary contacts (Class B)
//   CLASS_C   PEOPLE_EXPORT + PEOPLE_EXPORT_CLASS_C + reason + identity document numbers (Class C)
// Protected minors never receive Class B or C columns (the Children gate owns minor data).
// Every export is audited (class, row count, filters, scope roots) inside the same transaction.
final class ExportService
{
    public const STANDARD = 'STANDARD';
    public const SENSITIVE = 'SENSITIVE';
    public const CLASS_C = 'CLASS_C';

    private PersonRecords $people;

    public function __construct(private PeopleRuntime $rt)
    {
        $this->people = new PersonRecords($rt);
    }

    public function export(int $user, int $session, array $in): array
    {
        $class = (string) ($in['class'] ?? '');
        if (!in_array($class, [self::STANDARD, self::SENSITIVE, self::CLASS_C], true)) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'class']);
        }
        return $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($class, $in): array {
            $units = $guard->covered(PeopleCatalog::PEOPLE_EXPORT);
            $roots = array_keys($this->rt->authority->scopeRoots($actor, PeopleCatalog::PEOPLE_EXPORT));
            $required = match ($class) {
                self::SENSITIVE => [PeopleCatalog::PEOPLE_SENSITIVE_VIEW],
                self::CLASS_C => [PeopleCatalog::PEOPLE_EXPORT_CLASS_C],
                default => [],
            };
            foreach ($required as $permission) {
                $extra = $this->rt->authority->coveredUnits($actor, $permission);
                if ($extra === []) {
                    throw new PeopleError(PeopleReason::FORBIDDEN, ['permission' => $permission]);
                }
                $units = array_intersect_key($units, $extra);
            }
            $reason = isset($in['reason']) ? trim((string) $in['reason']) : null;
            if ($class === self::CLASS_C && ($reason === null || mb_strlen($reason) < 5)) {
                throw new PeopleError(PeopleReason::REASON_REQUIRED);
            }
            [$predicate, $bindings] = $this->rt->authority->scopePredicate('p', $units);
            $query = $this->rt->db->table('people as p')->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')->whereRaw($predicate, $bindings);
            if (!empty($in['search'])) {
                $query->where('p.full_name', 'like', '%' . PersonRecords::escapeLike(trim((string) $in['search'])) . '%');
            }
            if (!empty($in['status'])) {
                $query->where('ps.code', (string) $in['status']);
            }
            $max = (int) ($this->rt->settings['export_max_rows'] ?? 1000);
            $count = (clone $query)->count();
            if ($count > $max) {
                throw new PeopleError(PeopleReason::EXPORT_TOO_LARGE, ['rows' => $count]);
            }
            $rows = $query->orderBy('p.full_name')->orderBy('p.id')->get(['p.*', 'ps.code as status_code'])->all();
            $children = $this->people->childProfiles(array_map(fn (object $r): int => (int) $r->id, $rows));
            $header = ['public_id', 'nome', 'estado', 'precisao_nascimento', 'faixa_etaria'];
            if ($class === self::SENSITIVE) {
                $header = array_merge($header, ['data_nascimento', 'ano_nascimento', 'mes_nascimento', 'telefone_principal', 'email_principal']);
            }
            if ($class === self::CLASS_C) {
                $header[] = 'documentos_identidade';
            }
            $lines = [$header];
            $protectedCount = 0;
            foreach ($rows as $row) {
                $minimal = $this->people->minimal($row, isset($children[(int) $row->id]));
                $line = [$minimal['public_id'], $minimal['display_name'], $minimal['status'], $minimal['birth_precision'], (string) ($minimal['age_band'] ?? '')];
                $protected = $minimal['protected_minor'];
                $protectedCount += $protected ? 1 : 0;
                if ($class === self::SENSITIVE) {
                    $line = array_merge($line, $protected ? ['', '', '', '', ''] : $this->sensitiveColumns($row));
                }
                if ($class === self::CLASS_C) {
                    $line[] = $protected ? '' : $this->documents($row);
                }
                $lines[] = $line;
            }
            $unit = $roots !== [] ? (int) min($roots) : (int) array_key_first($units);
            $this->rt->audit->record($actor, $unit, 'PEOPLE_EXPORTED', 'people_export', 0, null, [
                'class' => $class,
                'row_count' => count($rows),
                'protected_minors_minimized' => $protectedCount,
                'filters' => ['search' => !empty($in['search']), 'status' => $in['status'] ?? null],
                'scope_roots' => $roots,
                'covered_unit_count' => count($units),
            ], $reason);
            return [
                'filename' => 'pessoas-' . strtolower($class) . '-' . $this->rt->now()->format('Ymd-His') . '.csv',
                'content_type' => 'text/csv; charset=UTF-8',
                'row_count' => count($rows),
                'csv' => $this->csv($lines),
            ];
        });
    }

    private function sensitiveColumns(object $row): array
    {
        $contacts = $this->rt->db->table('person_contacts as pc')->join('contact_types as ct', 'ct.id', '=', 'pc.contact_type_id')
            ->where('pc.person_id', $row->id)->where('pc.status', PeopleCatalog::LINK_ACTIVE)->where('pc.is_primary', 1)
            ->get(['pc.value_ciphertext', 'pc.key_version', 'ct.code'])->all();
        $primary = ['PHONE' => '', 'EMAIL' => ''];
        foreach ($contacts as $contact) {
            if (isset($primary[$contact->code]) && $row->status_code !== PeopleCatalog::PERSON_DECEASED) {
                $primary[$contact->code] = $this->rt->crypto()->decrypt((string) $contact->value_ciphertext, (int) $contact->key_version, 'mepa.people.contact.v1|' . $row->public_id);
            }
        }
        return [(string) ($row->birth_date ?? ''), $row->birth_year === null ? '' : (string) $row->birth_year, $row->birth_month === null ? '' : (string) $row->birth_month, $primary['PHONE'], $primary['EMAIL']];
    }

    private function documents(object $row): string
    {
        $docs = $this->rt->db->table('person_documents as d')->join('identity_document_types as t', 't.id', '=', 'd.document_type_id')
            ->where('d.person_id', $row->id)->orderBy('d.id')->get(['d.number_ciphertext', 'd.key_version', 't.code'])->all();
        return implode('; ', array_map(fn (object $d): string => $d->code . ':' . $this->rt->crypto()->decrypt((string) $d->number_ciphertext, (int) $d->key_version, 'mepa.people.document.number.v1|' . $row->public_id), $docs));
    }

    private function csv(array $lines): string
    {
        $out = "\u{FEFF}";
        foreach ($lines as $line) {
            $out .= implode(';', array_map(function (string $cell): string {
                // Formula-injection guard for spreadsheet tools.
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $cell = "'" . $cell;
                }
                return '"' . str_replace('"', '""', $cell) . '"';
            }, $line)) . "\r\n";
        }
        return $out;
    }
}
