<?php

declare(strict_types=1);

namespace App\Http\Academy;

use Illuminate\Database\Connection;

final class AcademyRouteResolver
{
    private const PUBLIC = [
        'programs', 'courses', 'cohorts', 'classes', 'enrollments', 'assessments', 'certificates', 'transcripts', 'people', 'resources',
    ];

    public function __construct(private Connection $db)
    {
    }

    public function id(string $table, mixed $value): int
    {
        if (in_array($table, self::PUBLIC, true)) {
            if (!is_string($value) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) !== 1) {
                return 0;
            }
            return (int) ($this->db->table($table)->where('public_id', $value)->value('id') ?? 0);
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    }
}
