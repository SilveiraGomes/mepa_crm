<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;

/** Canonical historical subtree at the end of a reporting period (ADR 0021 D15). */
final class ReportPerimeterResolver
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<int> root followed by its historical descendants */
    public function resolve(int $root, string $reportEndDate): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reportEndDate, new DateTimeZone(FinanceCatalog::TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $reportEndDate) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'to']);
        }
        $instant = $date->setTime(23, 59, 59, 999999)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $sql = <<<'SQL'
WITH RECURSIVE ranked AS (
  SELECT p.unit_id, p.parent_unit_id,
         ROW_NUMBER() OVER (PARTITION BY p.unit_id ORDER BY p.starts_at DESC, p.id DESC) AS rn
  FROM unit_parent_periods p
  WHERE p.starts_at <= ? AND (p.ends_at IS NULL OR p.ends_at > ?)
), parentage AS (
  SELECT unit_id, parent_unit_id FROM ranked WHERE rn = 1
), subtree AS (
  SELECT id, 0 AS depth FROM organizational_units WHERE id = ?
  UNION ALL
  SELECT o.id, s.depth + 1
  FROM subtree s JOIN parentage p ON p.parent_unit_id = s.id
  JOIN organizational_units o ON o.id = p.unit_id
  WHERE s.depth < 64
)
SELECT DISTINCT id FROM subtree ORDER BY id
SQL;
        return array_map(static fn (object $r): int => (int) $r->id, $this->db->select($sql, [$instant, $instant, $root]));
    }
}
